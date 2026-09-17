<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Cron\CronCommandProcessFactory;
use App\Core\Cron\CronCommandWhitelist;
use App\Core\Cron\CronExpressionEvaluator;
use App\Core\Cron\CronManager;
use App\Core\Cron\CronOverrideStore;
use App\Entity\CronJob;
use App\Entity\CronJobRun;
use App\Repository\CronJobRepository;
use App\Repository\CronJobRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Unified cron admin: DB [MANUAL] and code [CODE] tasks in one table.
 * CRUD applies to DB jobs only; Run Now works for both via CronCommandProcessFactory.
 *
 * Code tasks are not editable as such — their command lives in the source — but
 * their schedule and on/off state are an operator decision, stored as overrides
 * (see CronOverrideStore) so an update cannot quietly undo them.
 */
final class AACPCronController
{
    /** Job names come from #[CpCronJob], e.g. "security.maintenance". */
    private const JOB_NAME_PATTERN = '[A-Za-z0-9._-]+';

    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CronJobRepository $cronJobRepository,
        private readonly CronJobRunRepository $cronJobRunRepository,
        private readonly CronCommandWhitelist $cronCommandWhitelist,
        private readonly CronExpressionEvaluator $cronExpressionEvaluator,
        private readonly CronCommandProcessFactory $cronCommandProcessFactory,
        private readonly CronManager $cronManager,
        private readonly CronOverrideStore $cronOverrideStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $cronToken,
    ) {
    }

    #[Route('/aacp/cron', name: 'aacp_cron', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.cron', icon: 'heroicons:clock', panel: 'aacp', priority: 71, capability: 'system.cron.manage', parent: 'aacp_hub_automation')]
    #[IsGranted('system.cron.manage')]
    public function index(): Response
    {
        $html = $this->twig->render('aacp/cron/index.html.twig', [
            'tasks' => $this->cronManager->getTasks(),
            'endpoint' => $this->buildEndpointInfo(),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_cron')->getValue(),
        ]);

        return new Response($html);
    }

    /**
     * Saves the schedule and on/off state an operator chose for a code task.
     */
    #[Route('/aacp/cron/code/{jobName}/schedule', name: 'aacp_cron_code_schedule', methods: ['POST'], requirements: ['jobName' => self::JOB_NAME_PATTERN])]
    #[IsGranted('system.cron.manage')]
    public function saveCodeSchedule(string $jobName, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        if ($this->cronManager->findDefinitionByJobName($jobName) === null) {
            throw new NotFoundHttpException($this->translator->trans('aacp.cron.virtual_job_not_found', ['jobName' => $jobName]));
        }

        $expression = trim((string) $request->request->get('cron_expression'));
        $active = $request->request->get('active') !== null;

        // An empty box means "use the shipped schedule", so only a value that
        // was actually typed has to parse.
        if ($expression !== '' && !$this->cronExpressionEvaluator->isValidExpression($expression)) {
            return new RedirectResponse('/aacp/cron?'.http_build_query([
                'errors' => $this->translator->trans('aacp.cron.validation.invalid_expression'),
            ]));
        }

        $this->cronOverrideStore->save($jobName, $expression !== '' ? $expression : null, $active);

        return new RedirectResponse('/aacp/cron?schedule_saved=1');
    }

    /**
     * Drops the override so the task goes back to the schedule its code declares.
     */
    #[Route('/aacp/cron/code/{jobName}/reset', name: 'aacp_cron_code_reset', methods: ['POST'], requirements: ['jobName' => self::JOB_NAME_PATTERN])]
    #[IsGranted('system.cron.manage')]
    public function resetCodeSchedule(string $jobName, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $this->cronOverrideStore->forget($jobName);

        return new RedirectResponse('/aacp/cron?schedule_reset=1');
    }

    /**
     * Everything the operator needs to wire cron up at their host.
     *
     * The endpoint has existed since the beginning but the panel never showed
     * it, so the usual outcome was an installation where nothing scheduled ever
     * ran and nobody could see why. An empty CRON_TOKEN disables the endpoint
     * outright, which the screen has to say rather than print a URL that 401s.
     *
     * @return array{configured: bool, url: string, token: string, crontab: string, cli: string}
     */
    private function buildEndpointInfo(): array
    {
        $base = $this->urlGenerator->generate('cp_cron_execute', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $configured = $this->cronToken !== '';
        $url = $configured ? $base.'?token='.rawurlencode($this->cronToken) : $base;

        return [
            'configured' => $configured,
            'url' => $url,
            'token' => $this->cronToken,
            // Every five minutes: fine-grained enough for the shipped tasks,
            // coarse enough that a shared host will not complain. Quoted with a
            // literal single quote rather than escapeshellarg(), which would
            // follow *this* machine's shell — the line is pasted on the server.
            'crontab' => sprintf("*/5 * * * * curl -fsS '%s' > /dev/null 2>&1", $url),
            'cli' => 'php cp-core/bin/console cp:cron:run',
        ];
    }

    #[Route('/aacp/cron/new', name: 'aacp_cron_new', methods: ['GET'])]
    #[IsGranted('system.cron.manage')]
    public function new(): Response
    {
        $html = $this->twig->render('aacp/cron/form.html.twig', [
            'cronJob' => null,
            'allowedCommands' => $this->cronCommandWhitelist->allowedCommandNames(),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_cron')->getValue(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/cron/{id}/edit', name: 'aacp_cron_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.cron.manage')]
    public function edit(int $id): Response
    {
        $cronJob = $this->findOrFail($id);

        $html = $this->twig->render('aacp/cron/form.html.twig', [
            'cronJob' => $cronJob,
            'allowedCommands' => $this->cronCommandWhitelist->allowedCommandNames(),
            'recentRuns' => $this->cronJobRunRepository->findRecentByJob($cronJob),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_cron')->getValue(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/cron/save', name: 'aacp_cron_save', methods: ['POST'])]
    #[IsGranted('system.cron.manage')]
    public function save(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $id = $request->request->getInt('id') ?: null;
        $name = trim((string) $request->request->get('name'));
        $commandName = trim((string) $request->request->get('command_name'));
        $commandArguments = trim((string) $request->request->get('command_arguments'));
        $cronExpression = trim((string) $request->request->get('cron_expression'));
        $active = $request->request->get('active') !== null;

        $errors = $this->validate($name, $commandName, $cronExpression);

        if ($errors !== []) {
            return new RedirectResponse($this->buildFormRedirectUrl($id).'?'.http_build_query(['errors' => implode('|', $errors)]));
        }

        $cronJob = $id !== null ? $this->findOrFail($id) : new CronJob($name, $commandName, $cronExpression);

        $cronJob->setName($name)
            ->setCommandName($commandName)
            ->setCommandArguments($commandArguments !== '' ? $commandArguments : null)
            ->setCronExpression($cronExpression)
            ->setActive($active);

        if ($id === null) {
            $this->entityManager->persist($cronJob);
        }

        $this->entityManager->flush();

        return new RedirectResponse('/aacp/cron');
    }

    #[Route('/aacp/cron/{id}/delete', name: 'aacp_cron_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.cron.manage')]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $cronJob = $this->findOrFail($id);
        $this->entityManager->remove($cronJob);
        $this->entityManager->flush();

        return new RedirectResponse('/aacp/cron');
    }

    /**
     * Runs one job synchronously from AACP, skipping the due-time check (300s process timeout).
     */
    #[Route('/aacp/cron/{id}/run', name: 'aacp_cron_run_now', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.cron.manage')]
    public function runNow(int $id, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $cronJob = $this->findOrFail($id);
        $run = new CronJobRun($cronJob, triggeredManually: true);
        $this->entityManager->persist($run);

        if (!$this->cronCommandWhitelist->isAllowed($cronJob->getCommandName())) {
            $run->markFinished(false, $this->translator->trans('aacp.cron.command_not_whitelisted', ['command' => $cronJob->getCommandName()]));
            $this->entityManager->flush();

            return new RedirectResponse('/aacp/cron/'.$id.'/edit?run_rejected=1');
        }

        $process = $this->cronCommandProcessFactory->create($cronJob->getCommandName(), $cronJob->getCommandArguments());
        $process->run();

        $cronJob->markRunAt(new \DateTimeImmutable());
        $run->markFinished($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->entityManager->flush();

        return new RedirectResponse('/aacp/cron/'.$id.'/edit?run_completed=1');
    }

    /**
     * AJAX Run Now for DB and code tasks; updates last-run cell without page reload.
     */
    #[Route('/aacp/cron/run-now', name: 'aacp_cron_run_now_ajax', methods: ['POST'])]
    #[IsGranted('system.cron.manage')]
    public function runNowAjax(Request $request): JsonResponse
    {
        $this->assertValidCsrfToken($request);

        $type = (string) $request->request->get('type');

        return match ($type) {
            'db' => $this->runDatabaseJobAjax($request),
            'code' => $this->runVirtualJobAjax($request),
            default => new JsonResponse(['success' => false, 'output' => $this->translator->trans('aacp.cron.invalid_type')], Response::HTTP_BAD_REQUEST),
        };
    }

    private function runDatabaseJobAjax(Request $request): JsonResponse
    {
        $id = $request->request->getInt('id');
        $cronJob = $this->cronJobRepository->find($id);

        if (!$cronJob instanceof CronJob) {
            return new JsonResponse(['success' => false, 'output' => $this->translator->trans('aacp.cron.job_not_found', ['id' => $id])], Response::HTTP_NOT_FOUND);
        }

        $run = new CronJobRun($cronJob, triggeredManually: true);
        $this->entityManager->persist($run);

        if (!$this->cronCommandWhitelist->isAllowed($cronJob->getCommandName())) {
            $message = $this->translator->trans('aacp.cron.command_not_whitelisted', ['command' => $cronJob->getCommandName()]);
            $run->markFinished(false, $message);
            $this->entityManager->flush();

            return new JsonResponse(['success' => false, 'output' => $message]);
        }

        $process = $this->cronCommandProcessFactory->create($cronJob->getCommandName(), $cronJob->getCommandArguments());
        $process->run();

        $now = new \DateTimeImmutable();
        $cronJob->markRunAt($now);
        $output = $process->getOutput().$process->getErrorOutput();
        $run->markFinished($process->isSuccessful(), $output);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => $process->isSuccessful(),
            'output' => $output,
            'lastRunAt' => $now->format('d.m.Y H:i:s'),
        ]);
    }

    private function runVirtualJobAjax(Request $request): JsonResponse
    {
        $jobName = trim((string) $request->request->get('jobName'));

        if ($jobName === '' || $this->cronManager->findDefinitionByJobName($jobName) === null) {
            return new JsonResponse(['success' => false, 'output' => $this->translator->trans('aacp.cron.virtual_job_not_found', ['jobName' => $jobName])], Response::HTTP_NOT_FOUND);
        }

        $process = $this->cronCommandProcessFactory->create('cp:cron:run-virtual', $jobName);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        return new JsonResponse([
            'success' => $process->isSuccessful(),
            'output' => $output,
            'lastRunAt' => (new \DateTimeImmutable())->format('d.m.Y H:i:s'),
        ]);
    }

    private function findOrFail(int $id): CronJob
    {
        $cronJob = $this->cronJobRepository->find($id);
        if (!$cronJob instanceof CronJob) {
            throw new NotFoundHttpException($this->translator->trans('aacp.cron.job_not_found', ['id' => $id]));
        }

        return $cronJob;
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_cron', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.cron.invalid_csrf'));
        }
    }

    /**
     * @return list<string>
     */
    private function validate(string $name, string $commandName, string $cronExpression): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = $this->translator->trans('aacp.cron.validation.name_required');
        }

        if (!$this->cronCommandWhitelist->isAllowed($commandName)) {
            $errors[] = $this->translator->trans('aacp.cron.validation.command_not_allowed');
        }

        if (!$this->cronExpressionEvaluator->isValidExpression($cronExpression)) {
            $errors[] = $this->translator->trans('aacp.cron.validation.invalid_expression');
        }

        return $errors;
    }

    private function buildFormRedirectUrl(?int $id): string
    {
        return $id !== null ? '/aacp/cron/'.$id.'/edit' : '/aacp/cron/new';
    }
}
