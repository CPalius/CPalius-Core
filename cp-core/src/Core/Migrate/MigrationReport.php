<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * What a run did, or would do.
 *
 * The five outcomes are kept apart on purpose. "Skipped" (the migration
 * deliberately filtered the row) and "unchanged" (the row is already imported
 * and identical) and "failed" all collapse into "not imported" in most tools,
 * which is why an operator staring at "3 000 of 10 000 imported" cannot tell
 * whether the import worked. Here the three have separate counters and only
 * one of them is a problem.
 *
 * Failures carry the source id, because "row 4 812 failed" is useless when the
 * next run reorders the stream; the source id is the thing an operator can look
 * up in the system they are migrating away from.
 */
final class MigrationReport
{
    /** @var list<array{sourceId: string, message: string}> */
    private array $failures = [];

    private int $created = 0;

    private int $updated = 0;

    private int $unchanged = 0;

    private int $skipped = 0;

    private bool $limitReached = false;

    public function __construct(
        public readonly string $migrationId,
        public readonly bool $dryRun,
    ) {
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    public function unchanged(): int
    {
        return $this->unchanged;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    public function failed(): int
    {
        return \count($this->failures);
    }

    /**
     * @return list<array{sourceId: string, message: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function limitReached(): bool
    {
        return $this->limitReached;
    }

    /**
     * Rows the source produced and the runner looked at.
     */
    public function processed(): int
    {
        return $this->created + $this->updated + $this->unchanged + $this->skipped + $this->failed();
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function recordCreated(): void
    {
        ++$this->created;
    }

    public function recordUpdated(): void
    {
        ++$this->updated;
    }

    public function recordUnchanged(): void
    {
        ++$this->unchanged;
    }

    public function recordSkipped(): void
    {
        ++$this->skipped;
    }

    public function recordFailure(string $sourceId, string $message): void
    {
        $this->failures[] = ['sourceId' => $sourceId, 'message' => $message];
    }

    public function markLimitReached(): void
    {
        $this->limitReached = true;
    }

    /**
     * @return array{migration: string, dryRun: bool, processed: int, created: int, updated: int, unchanged: int, skipped: int, failed: int, limitReached: bool, failures: list<array{sourceId: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'migration' => $this->migrationId,
            'dryRun' => $this->dryRun,
            'processed' => $this->processed(),
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'failed' => $this->failed(),
            'limitReached' => $this->limitReached,
            'failures' => $this->failures,
        ];
    }
}
