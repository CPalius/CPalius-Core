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
 * POST only; secured by CRON_TOKEN via Bearer/X-Cron-Token header or POST body (timing-safe); empty token disables the endpoint; no session required.
 */
final class CronExecuteController
{
    private const USAGE = 'POST this URL with header "Authorization: Bearer <CRON_TOKEN>" or "X-Cron-Token: <CRON_TOKEN>" (query-string tokens are no longer accepted).';

    public function __construct(
        private readonly CronDispatcher $cronDispatcher,
        private readonly string $cronToken,
    ) {
    }

    #[Route('/cron/execute', name: 'cp_cron_execute', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        // Tokens in URLs leak into access logs, proxies and Referer headers, so
        // only POST with a header (or body) token is accepted; old GET ?token= callers get a hint.
        if (!$request->isMethod('POST')) {
            return new JsonResponse(
                ['error' => 'Method Not Allowed', 'hint' => self::USAGE],
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => 'POST'],
            );
        }

        $submittedToken = $this->submittedToken($request);

        if ($this->cronToken === '' || $submittedToken === '' || !hash_equals($this->cronToken, $submittedToken)) {
            return new JsonResponse(['error' => 'Unauthorized', 'hint' => self::USAGE], Response::HTTP_UNAUTHORIZED);
        }

        $results = $this->cronDispatcher->runDueTasks();

        return new JsonResponse([
            'executedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'taskCount' => \count($results),
            'results' => $results,
        ]);
    }

    private function submittedToken(Request $request): string
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        $header = (string) $request->headers->get('X-Cron-Token', '');

        return $header !== '' ? $header : (string) $request->request->get('token', '');
    }
}
