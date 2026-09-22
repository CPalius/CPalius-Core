<?php

declare(strict_types=1);

namespace App\Core\Rebuild;

use App\Core\Database\QueryCounter;
use Psr\Log\LoggerInterface;

/**
 * One AJAX page of a rebuilder. Controllers stay thin: CSRF and which panel.
 *
 * @phpstan-type BatchResult array{
 *     ok: bool,
 *     id: string,
 *     processed: int,
 *     offset: int,
 *     total: int,
 *     done: bool,
 *     percent: int
 * }
 */
final class RebuildBatchRunner
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?QueryCounter $queryCounter = null,
    ) {
    }

    /**
     * @return BatchResult
     */
    public function run(RebuilderInterface $rebuilder, int $offset): array
    {
        $limit = $rebuilder->getBatchSize();
        if ($limit < 1) {
            $limit = 1;
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $total = max(0, $rebuilder->getTotal());
        $offset = max(0, $offset);

        try {
            $this->queryCounter?->suspend();
            $processed = max(0, $rebuilder->rebuild($offset, $limit));
        } catch (\Throwable $e) {
            $this->logger?->error('Rebuild batch failed.', [
                'rebuilder' => $rebuilder->getId(),
                'offset' => $offset,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $this->queryCounter?->resume();
        }

        $nextOffset = $offset + $processed;
        $done = $processed < $limit;
        $percent = 100;
        if (!$done && $total > 0) {
            $percent = (int) min(99, (int) floor(($nextOffset / $total) * 100));
        } elseif (!$done) {
            $percent = 0;
        }

        return [
            'ok' => true,
            'id' => $rebuilder->getId(),
            'processed' => $processed,
            'offset' => $nextOffset,
            'total' => $total,
            'done' => $done,
            'percent' => $percent,
        ];
    }
}
