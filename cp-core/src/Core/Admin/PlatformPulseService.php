<?php

declare(strict_types=1);

namespace App\Core\Admin;

use App\Core\Cron\CronManager;
use App\Core\Diagnostics\Doctor;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Entity\EntityTypeRegistry;
use App\Core\Logging\Entity\LogEntry;
use App\Core\Mail\Entity\MailLog;
use App\Core\Module\ModuleRegistry;
use App\Core\Queue\QueueStatusService;
use App\Entity\CronJob;
use App\Repository\CronJobRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Read model for the AACP command desk: what an operator of ANY CPalius
 * application needs to know, independent of what that application is.
 *
 * The dashboard used to answer a website's questions (page views, top pages,
 * visiting IPs). Those are real, but they are meaningful for only one of the
 * application shapes CPalius targets. A CRM, an ERP, a hosting panel or a
 * staff-management install has no "top page" worth a quarter of the screen.
 * The four questions below survive every shape:
 *
 *   1. work      - is queued and scheduled work actually getting done?
 *   2. faults    - is anything failing right now?
 *   3. integrity - is this installation in the state its own code expects?
 *   4. volume    - how much of each entity type does it hold?
 *
 * Law 2.3 discipline: every section is independently guarded. A missing table
 * or a broken module degrades one panel to "unavailable"; it never takes the
 * command desk down, because the command desk is where an operator goes
 * precisely when something is already broken.
 */
final class PlatformPulseService
{
    /**
     * The integrity section runs the full cp:doctor suite (migration repository
     * reads, translation catalogue walks, a security posture pass). That is
     * fine once and wrong on every dashboard load, so it is cached. The TTL is
     * short enough that a fix shows up while the operator is still on the page,
     * and the panel prints the timestamp rather than implying it is live.
     */
    private const INTEGRITY_TTL = 300;

    /** Entity counts are cheap but not free: one COUNT per registered type. */
    private const VOLUME_TTL = 60;

    /** Anything at or above ERROR is a fault; WARNING is noise at this altitude. */
    private const FAULT_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(
        private readonly QueueStatusService $queueStatus,
        private readonly CronManager $cronManager,
        private readonly CronJobRunRepository $cronJobRunRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EntityTypeRegistry $entityTypes,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly Doctor $doctor,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $appCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     work: array<string, mixed>,
     *     faults: array<string, mixed>,
     *     integrity: array<string, mixed>,
     *     volume: array<string, mixed>,
     *     generatedAt: string
     * }
     */
    public function build(int $hours = 24): array
    {
        return [
            'work' => $this->section('work', fn (): array => $this->buildWork($hours)),
            'faults' => $this->section('faults', fn (): array => $this->buildFaults($hours)),
            'integrity' => $this->section('integrity', fn (): array => $this->buildIntegrity()),
            'volume' => $this->section('volume', fn (): array => $this->buildVolume()),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * One guarded section. The failure shape is the success shape plus
     * available:false, so a template never has to ask whether it received an
     * array or an error.
     *
     * @param callable(): array<string, mixed> $builder
     *
     * @return array<string, mixed>
     */
    private function section(string $name, callable $builder): array
    {
        try {
            return ['available' => true] + $builder();
        } catch (\Throwable $e) {
            $this->logger->warning('AACP platform pulse section failed; rendering it as unavailable.', [
                'section' => $name,
                'exception' => $e->getMessage(),
            ]);

            return ['available' => false];
        }
    }

    /**
     * Is queued and scheduled work getting done? Reports the two queues
     * separately, as QueueStatusService does, and never sums a fake total.
     *
     * @return array<string, mixed>
     */
    private function buildWork(int $hours): array
    {
        $queue = $this->queueStatus->summary();
        $since = $this->since($hours);

        $cronTotal = 0;
        $cronInactive = 0;
        $cronFailing = [];
        $lastRunAt = null;

        foreach ($this->cronManager->getTasks() as $task) {
            if (!$task instanceof CronJob) {
                continue;
            }

            ++$cronTotal;
            if (!$task->isActive()) {
                ++$cronInactive;
            }

            $runAt = $task->getLastRunAt();
            if ($runAt instanceof \DateTimeImmutable && ($lastRunAt === null || $runAt > $lastRunAt)) {
                $lastRunAt = $runAt;
            }

            // One failure may just be a locked row; consecutive failures mean
            // the job is broken, and the operator has to know which one.
            try {
                if ($this->cronJobRunRepository->countConsecutiveFailures($task) > 0) {
                    $cronFailing[] = $task->getName();
                }
            } catch (\Throwable) {
                // Run history is optional; the task tally still renders.
            }
        }

        return [
            'queue' => $queue,
            'cron' => [
                'total' => $cronTotal,
                'inactive' => $cronInactive,
                'failing' => $cronFailing,
                'lastRunAt' => $lastRunAt?->format(DATE_ATOM),
            ],
            'mail' => [
                'queued' => $this->countMailByStatus(MailLog::STATUS_QUEUED, null),
                'failed' => $this->countMailByStatus(MailLog::STATUS_FAILED, $since),
                'sent' => $this->countMailByStatus(MailLog::STATUS_SENT, $since),
            ],
        ];
    }

    /**
     * Is anything failing right now? Counts application faults from the
     * watchdog table (T3.5) rather than from web traffic: an ERP install with
     * ten users and a broken invoicing job is in worse shape than a site with
     * no visitors at all, and only this panel can say so.
     *
     * @return array<string, mixed>
     */
    private function buildFaults(int $hours): array
    {
        $since = $this->since($hours);

        /** @var list<array{level: string, channel: string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('l.level AS level, l.channel AS channel, COUNT(l.id) AS total')
            ->from(LogEntry::class, 'l')
            ->where('l.createdAt >= :since')
            ->andWhere('l.level IN (:levels)')
            ->setParameter('since', $since)
            ->setParameter('levels', self::FAULT_LEVELS)
            ->groupBy('l.level')
            ->addGroupBy('l.channel')
            ->getQuery()
            ->getArrayResult();

        $errors = 0;
        $critical = 0;
        $byChannel = [];

        foreach ($rows as $row) {
            $count = (int) $row['total'];
            $channel = (string) $row['channel'];

            $errors += $count;
            if (strtoupper((string) $row['level']) !== 'ERROR') {
                $critical += $count;
            }

            $byChannel[$channel] = ($byChannel[$channel] ?? 0) + $count;
        }

        arsort($byChannel);
        $topChannels = [];
        foreach (\array_slice($byChannel, 0, 4, true) as $channel => $count) {
            $topChannels[] = ['channel' => $channel, 'count' => $count];
        }

        return [
            'hours' => $hours,
            'errors' => $errors,
            'critical' => $critical,
            'topChannels' => $topChannels,
            'quarantinedModules' => $this->countQuarantinedModules(),
        ];
    }

    /**
     * Returns null when module discovery itself failed. "Cannot tell" must not
     * render as "none quarantined" on the screen an operator opens when the
     * installation is misbehaving.
     */
    private function countQuarantinedModules(): ?int
    {
        try {
            $count = 0;
            foreach ($this->moduleRegistry->discoverAllModules() as $module) {
                if ($module['status'] === 'quarantined') {
                    ++$count;
                }
            }

            return $count;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Is this installation in the state its own code expects? This is the most
     * under-reported failure class in every CMS: an unapplied migration or an
     * unrun update hook produces silence, not an error page (see
     * PendingMigrationsCheck for the incident that motivated cp:doctor).
     *
     * @return array<string, mixed>
     */
    private function buildIntegrity(): array
    {
        /** @var array{checkedAt: string, summary: array<string, int>, blocking: int, findings: list<array<string, mixed>>} $cached */
        $cached = $this->appCache->get('aacp.pulse.integrity', function (CacheItemInterface $item): array {
            $item->expiresAfter(self::INTEGRITY_TTL);

            $findings = $this->doctor->run();
            $summary = $this->doctor->summary($findings);

            $attention = [];
            foreach ($findings as $finding) {
                if (!$finding->isPass()) {
                    $attention[] = $finding->toArray();
                }
            }

            return [
                'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'summary' => $summary,
                'blocking' => ($summary[DoctorFinding::SEVERITY_CRITICAL] ?? 0)
                    + ($summary[DoctorFinding::SEVERITY_HIGH] ?? 0),
                'findings' => \array_slice($attention, 0, 5),
            ];
        });

        return $cached;
    }

    /**
     * How much of each entity type does this installation hold? Driven by
     * #[CpEntityType] instead of a hardcoded list, so a CRM module's Contact
     * appears here exactly the way Node does. The panel describes whatever the
     * installation is, without core needing to know what that is.
     *
     * @return array{types: list<array{id: string, label: string, count: int}>}
     */
    private function buildVolume(): array
    {
        /** @var list<array{id: string, label: string, count: int}> $types */
        $types = $this->appCache->get('aacp.pulse.volume', function (CacheItemInterface $item): array {
            $item->expiresAfter(self::VOLUME_TTL);

            $out = [];
            foreach ($this->entityTypes->all() as $definition) {
                $count = $this->countEntities($definition->className);
                if ($count === null) {
                    continue;
                }

                $out[] = [
                    'id' => $definition->id,
                    'label' => $definition->label,
                    'count' => $count,
                ];
            }

            usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            return $out;
        });

        return ['types' => $types];
    }

    /**
     * Counts one entity type, excluding soft-deleted rows when the class has a
     * deletedAt field. Returns null when the type cannot be counted (no
     * mapping, missing table) so the caller omits the row instead of printing
     * zero: "no contacts yet" and "the contacts table is gone" are different
     * facts and must not share a rendering.
     */
    private function countEntities(string $className): ?int
    {
        // A registry entry can outlive the class it names (a module removed
        // from disk while its compiled definition is still cached). That is a
        // skipped row, not a fatal on the command desk.
        if (!class_exists($className)) {
            return null;
        }

        try {
            $metadata = $this->entityManager->getClassMetadata($className);

            $qb = $this->entityManager->createQueryBuilder()
                ->select('COUNT(e.'.$metadata->getSingleIdentifierFieldName().')')
                ->from($className, 'e');

            if ($metadata->hasField('deletedAt')) {
                $qb->where('e.deletedAt IS NULL');
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return null;
        }
    }

    private function countMailByStatus(string $status, ?\DateTimeImmutable $since): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('COUNT(m.id)')
                ->from(MailLog::class, 'm')
                ->where('m.status = :status')
                ->setParameter('status', $status);

            if ($since instanceof \DateTimeImmutable) {
                $qb->andWhere('m.createdAt >= :since')->setParameter('since', $since);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function since(int $hours): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('-%d hours', max(1, $hours)));
    }
}
