<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Localization\LocaleProvider;
use App\Core\Performance\Metrics\RequestMetrics;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Measures what a page costs when the origin cache does NOT absorb it.
 *
 * Each path is requested as an anonymous visitor through the real kernel, in this
 * process, many times. Between requests the services a long-running worker would
 * reset are reset and the entity manager is cleared, so a repeat costs what a
 * fresh request costs, not what a warmed-up identity map makes it cost.
 *
 * What the numbers are and are not:
 *  - Queries per request and the repeated-statement list are exact, and are the
 *    most useful output: an N+1 shows up as one statement with a large count.
 *  - Milliseconds are a warm worker's (code already compiled, like PHP-FPM with
 *    OPcache). A cold process, or a slow disk, costs more.
 *  - Nothing here is signed in, so member and admin pages are not covered.
 */
#[AsCommand(name: 'cp:perf:probe', description: 'Measure time, memory and queries per page (anonymous, origin cache bypassed).')]
final class PerfProbeCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RequestMetrics $metrics,
        private readonly EntityManagerInterface $em,
        private readonly LocaleProvider $locales,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Paths to request (default: home, forum, blog in the default language)')
            ->addOption('runs', 'r', InputOption::VALUE_REQUIRED, 'Measured requests per path', '10')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Requests per path discarded first (code and cache warm-up)', '2')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host header', 'localhost')
            ->addOption('repeat-min', null, InputOption::VALUE_REQUIRED, 'List statements that ran at least this many times in one request', '5')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Sign in as this user (e-mail or username) so member pages are measured too')
            ->addOption('profile', null, InputOption::VALUE_NONE, 'Debug mode only: show where the time of the last request went (listeners, controller, templates)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'List every distinct statement of the last request instead of the top few')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runs = max(1, (int) $input->getOption('runs'));
        $warmup = max(0, (int) $input->getOption('warmup'));
        $repeatMin = max(2, (int) $input->getOption('repeat-min'));
        $host = (string) $input->getOption('host');

        $paths = array_values(array_filter((array) $input->getArgument('paths'), 'is_string'));
        if ($paths === []) {
            $locale = $this->locales->getCodes()[0] ?? 'tr';
            $paths = ['/'.$locale, '/'.$locale.'/forum', '/'.$locale.'/blog'];
        }

        $as = null;
        if ((string) $input->getOption('as') !== '') {
            $identifier = (string) $input->getOption('as');
            $as = $this->users->findOneBy(['email' => $identifier]) ?? $this->users->findOneBy(['username' => $identifier]);
            if ($as === null) {
                $io->error('No such user: '.$identifier);

                return Command::FAILURE;
            }
        }

        $this->metrics->trace = true;
        $report = [];
        foreach ($paths as $path) {
            $report[] = $this->measure($path, $host, $warmup, $runs, $repeatMin, (bool) $input->getOption('all'), $as);
        }
        $this->metrics->trace = false;

        if ($input->getOption('profile')) {
            $this->printProfile($io);
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('cp:perf:probe — anonymous, origin cache bypassed, '.$runs.' runs per path');
        $io->table(
            ['Path', 'HTTP', 'median ms', 'p95 ms', 'queries', 'db ms', 'peak MB', 'bytes'],
            array_map(static fn (array $r): array => [
                $r['path'], $r['status'], $r['medianMs'], $r['p95Ms'], $r['queries'], $r['dbMs'], $r['peakMb'], $r['bytes'],
            ], $report),
        );

        foreach ($report as $r) {
            if ($r['repeated'] === []) {
                continue;
            }
            $io->section('Repeated statements on '.$r['path'].' (same SQL shape, '.$repeatMin.'+ times in one request)');
            $io->table(['times', 'ms total', 'statement'], array_map(static fn (array $s): array => [$s['count'], $s['ms'], $s['sql']], $r['repeated']));
        }
        foreach ($report as $r) {
            if ($r['slowest'] === []) {
                continue;
            }
            $io->section('Slowest statements on '.$r['path']);
            $io->table(['ms total', 'times', 'statement'], array_map(static fn (array $s): array => [$s['ms'], $s['count'], $s['sql']], $r['slowest']));
        }

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function measure(string $path, string $host, int $warmup, int $runs, int $repeatMin, bool $all = false, ?\App\Entity\User $as = null): array
    {
        $times = [];
        $last = ['status' => 0, 'queries' => 0, 'dbMs' => 0.0, 'bytes' => 0, 'statements' => []];

        for ($i = 0; $i < $warmup + $runs; ++$i) {
            $this->resetWorker();
            $request = Request::create('http://'.$host.$path, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            // An identity cookie makes OriginCacheReader step aside; without it the first request would just
            // measure the disk snapshot (a few ms, one query) instead of the page.
            $request->cookies->set('PHPSESSID', 'probe');
            $session = new Session(new MockArraySessionStorage());
            $request->setSession($session);
            if ($as !== null) {
                // What a signed-in browser carries: a session whose firewall context holds the token. The real
                // firewall then loads and refreshes the user, so capabilities and member-only queries are exercised.
                $session->start();
                $session->set('_security_main', serialize(new UsernamePasswordToken($as, 'main', $as->getRoles())));
                $request->cookies->set($session->getName(), $session->getId());
            }

            $t = hrtime(true);
            $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            $ms = (hrtime(true) - $t) / 1e6;

            if ($i < $warmup) {
                continue;
            }
            $times[] = $ms;
            $last = [
                'status' => $response->getStatusCode(),
                'queries' => $this->metrics->queries,
                'dbMs' => $this->metrics->dbSeconds * 1000,
                'bytes' => \strlen((string) $response->getContent()),
                'statements' => $this->metrics->statements(),
            ];
        }

        sort($times);
        $statements = [];
        foreach ($last['statements'] as $sql => $s) {
            $statements[] = ['sql' => $sql, 'count' => $s['count'], 'ms' => round($s['seconds'] * 1000, 1)];
        }
        $repeated = array_values(array_filter($statements, static fn (array $s): bool => $s['count'] >= $repeatMin));
        usort($repeated, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        usort($statements, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return [
            'path' => $path,
            'status' => $last['status'],
            'medianMs' => round($times[(int) floor((\count($times) - 1) / 2)], 1),
            'p95Ms' => round($times[(int) min(\count($times) - 1, floor(\count($times) * 0.95))], 1),
            'queries' => $last['queries'],
            'dbMs' => round($last['dbMs'], 1),
            'peakMb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'bytes' => $last['bytes'],
            'repeated' => \array_slice($repeated, 0, 8),
            'slowest' => $all ? $statements : \array_slice($statements, 0, 5),
        ];
    }

    private function printProfile(SymfonyStyle $io): void
    {
        $container = $this->kernel->getContainer();
        $stopwatch = $container->has('debug.stopwatch') ? $container->get('debug.stopwatch') : null;
        if (!$stopwatch instanceof \Symfony\Component\Stopwatch\Stopwatch) {
            $io->note('No stopwatch: --profile needs the debug kernel (APP_DEBUG=1).');

            return;
        }
        $rows = [];
        foreach ($stopwatch->getSections() as $section) {
            foreach ($section->getEvents() as $name => $event) {
                $rows[] = [$name, $event->getDuration(), \count($event->getPeriods())];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $io->section('Where the time went (last request, debug kernel; events overlap, so they do not sum)');
        $io->table(['event', 'ms', 'calls'], array_map(static fn (array $r): array => [$r[0], round($r[1], 1), $r[2]], \array_slice($rows, 0, 25)));
    }

    /** What a fresh request would start from: no identity map, no per-request service state. */
    private function resetWorker(): void
    {
        $this->em->clear();
        $resetter = $this->kernel->getContainer()->get('services_resetter');
        if ($resetter instanceof ResetInterface) {
            $resetter->reset();
        }
    }
}
