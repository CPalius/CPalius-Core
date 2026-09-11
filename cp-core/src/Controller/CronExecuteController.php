<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Cron\CronDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP cron entry for hosts without crontab; calls CronDispatcher::runDueTasks() like cp:cron:run.
 * Secured by CRON_TOKEN (timing-safe); empty token disables the endpoint; no session required.
 */
final class CronExecuteController
{
    public function __construct(
        private readonly CronDispatcher $cronDispatcher,
        private readonly string $cronToken,
    ) {
    }

    #[Route('/cron/execute', name: 'cp_cron_execute', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $submittedToken = (string) $request->query->get('token', $request->request->get('token', ''));

        if ($this->cronToken === '' || $submittedToken === '' || !hash_equals($this->cronToken, $submittedToken)) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $results = $this->cronDispatcher->runDueTasks();

        return new JsonResponse([
            'executedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'taskCount' => \count($results),
            'results' => $results,
        ]);
    }
}
