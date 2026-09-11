<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Audit\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Append-only machine access log via cp_audit_logs. Never stores the plaintext key.
 */
final class ApiAccessAuditor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function record(string $action, ?ApiKey $apiKey, string $path, int $status, int $durationMs, string $clientIp, array $meta = []): void
    {
        $changes = [
            'path' => [null, $path],
            'status' => [null, $status],
            'duration_ms' => [null, $durationMs],
            'ip' => [null, $this->redactIp($clientIp)],
        ];

        foreach ($meta as $field => $value) {
            if (!\is_string($field) || $field === '' || \is_array($value) || \is_object($value)) {
                continue;
            }
            $changes[$field] = [null, $value];
        }

        $log = new AuditLog(
            'api.access',
            $apiKey?->id,
            $action,
            $changes,
            null,
        );

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (Throwable $e) {
            $this->logger->warning('API access audit write failed.', ['exception' => $e->getMessage()]);
        }
    }

    private function redactIp(string $ip): string
    {
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);
            if (\count($parts) === 4) {
                $parts[3] = '0';

                return implode('.', $parts);
            }
        }

        return $ip !== '' ? substr($ip, 0, 8).'…' : '';
    }
}
