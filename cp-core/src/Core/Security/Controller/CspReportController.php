<?php

declare(strict_types=1);

namespace App\Core\Security\Controller;

use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Http\SecurityHeaderPolicy;
use App\Core\Security\Service\SecurityEventRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives browser CSP violation reports so the operator can move from
 * report-only to enforce with evidence instead of guesswork.
 *
 * Public by necessity (browsers post it unauthenticated), so it is flood-limited
 * per IP and the stored payload is truncated to the few fields that matter.
 */
final class CspReportController
{
    private const FLOOD_LIMIT = 30;
    private const FLOOD_WINDOW = 300;
    private const MAX_BODY = 8192;

    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly FloodService $flood,
    ) {
    }

    #[Route(SecurityHeaderPolicy::REPORT_PATH, name: 'cp_csp_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $ip = (string) ($request->getClientIp() ?: '0.0.0.0');

        if (!$this->flood->isAllowed(FloodService::EVENT_CSP_REPORT, $ip, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $this->flood->register(FloodService::EVENT_CSP_REPORT, $ip, self::FLOOD_WINDOW);

        // The declared length is checked before getContent() is ever called: that
        // call buffers the whole body into memory, so checking the cap afterwards
        // meant an unauthenticated caller could still make us hold an arbitrarily
        // large POST first and only then be told it was too big.
        $declaredLength = (int) $request->headers->get('Content-Length', '0');
        if ($declaredLength > self::MAX_BODY) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $report = $this->extract((string) $request->getContent());
        if ($report !== []) {
            $this->recorder->record(
                SystemTelemetryLog::EVENT_CSP_VIOLATION,
                SystemTelemetryLog::SEVERITY_WARNING,
                20,
                $report,
                $request,
            );
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, string>
     */
    private function extract(string $body): array
    {
        if ($body === '' || \strlen($body) > self::MAX_BODY) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $report = $decoded['csp-report'] ?? $decoded;
        if (!\is_array($report)) {
            return [];
        }

        $fields = [
            'violated_directive' => 'violated-directive',
            'effective_directive' => 'effective-directive',
            'blocked_uri' => 'blocked-uri',
            'document_uri' => 'document-uri',
            'disposition' => 'disposition',
            'source_file' => 'source-file',
        ];

        $out = [];
        foreach ($fields as $label => $key) {
            $value = $report[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                $out[$label] = mb_substr($value, 0, 300);
            }
        }

        return $out;
    }
}
