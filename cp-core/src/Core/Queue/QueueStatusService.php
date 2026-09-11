<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Queue\Repository\AsyncJobRepository;
use Doctrine\DBAL\Connection;

/**
 * Unified read model for AACP queue KPIs — Messenger (mail/notifications) and
 * platform AsyncJob (webhooks). Never merges the two into one fake number.
 */
final class QueueStatusService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AsyncJobRepository $asyncJobs,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     pending: int,
     *     messengerPending: int,
     *     messengerFailed: int,
     *     platformPending: int,
     *     platformFailed: int
     * }
     */
    public function summary(): array
    {
        $messengerPending = $this->countMessenger('async');
        $messengerFailed = $this->countMessenger('failed');
        $platformPending = $this->safePlatformPending();
        $platformFailed = $this->safePlatformFailed();

        return [
            'available' => true,
            'pending' => $messengerPending + $platformPending,
            'messengerPending' => $messengerPending,
            'messengerFailed' => $messengerFailed,
            'platformPending' => $platformPending,
            'platformFailed' => $platformFailed,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMessenger(string $queueName, int $limit = 50): array
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, body, headers, queue_name, created_at, available_at, delivered_at
                 FROM messenger_messages
                 WHERE queue_name = :queue
                 ORDER BY available_at ASC, id ASC
                 LIMIT '.$limit,
                ['queue' => $queueName],
            );

            return array_map(fn (array $row): array => $this->decorateMessengerRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{id: int, type: string, attempts: int, availableAt: string, lastError: ?string, failed: bool}>
     */
    public function listPlatformJobs(int $limit = 50, bool $failedOnly = false): array
    {
        return $this->asyncJobs->listRecent($limit, $failedOnly);
    }

    public function retryFailedMessenger(int $id): bool
    {
        try {
            $affected = $this->connection->executeStatement(
                'UPDATE messenger_messages
                 SET queue_name = :async, available_at = :now, delivered_at = NULL
                 WHERE id = :id AND queue_name = :failed',
                [
                    'async' => 'async',
                    'failed' => 'failed',
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'id' => $id,
                ],
            );

            return $affected > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteMessenger(int $id): bool
    {
        try {
            return $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE id = :id',
                ['id' => $id],
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function countMessenger(string $queueName): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NULL',
                ['queue' => $queueName],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safePlatformPending(): int
    {
        try {
            return $this->asyncJobs->countPending();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safePlatformFailed(): int
    {
        try {
            return $this->asyncJobs->countFailed();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decorateMessengerRow(array $row): array
    {
        $class = null;
        $headers = $row['headers'] ?? null;
        if (\is_string($headers) && $headers !== '') {
            try {
                $decoded = json_decode($headers, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $type = $decoded['type'] ?? $decoded['X-Message-Stamp-Symfony\Component\Messenger\Stamp\BusNameStamp'] ?? null;
                    if (\is_array($type) && isset($type[0]['type'])) {
                        $class = (string) $type[0]['type'];
                    } elseif (\is_string($decoded['type'] ?? null)) {
                        $class = (string) $decoded['type'];
                    }
                }
            } catch (\Throwable) {
                $class = null;
            }
        }

        // Symfony Doctrine transport encodes the class name in the body envelope.
        if ($class === null && \is_string($row['body'] ?? null)) {
            if (preg_match('/"([A-Za-z0-9_\\\\]+Message)"/', (string) $row['body'], $m) === 1) {
                $class = $m[1];
            }
        }

        $row['messageClass'] = $class ?? 'unknown';
        $row['bodyPreview'] = mb_substr((string) ($row['body'] ?? ''), 0, 160);

        return $row;
    }
}
