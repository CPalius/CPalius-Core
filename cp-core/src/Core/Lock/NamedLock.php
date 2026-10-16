<?php

declare(strict_types=1);

namespace App\Core\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Cross-process mutual exclusion on a name ("tour:42:2026-11-02", "invoice-numbers"), backed by the database.
 *
 * Use it for check-then-write sequences that two requests must never interleave, such as "is a seat
 * free? then book it". A file lock cannot do that on more than one server; this works wherever the
 * database is shared.
 *
 * MySQL/MariaDB: GET_LOCK() is held per connection and released when the connection closes, so a
 * crashed request cannot leave it stuck. It is not transactional: take the lock BEFORE the transaction
 * starts and release it AFTER commit, or the next holder reads a snapshot from before your write.
 * inTransaction() does exactly that.
 *
 * Other platforms (SQLite in tests, a single-user install): a no-op that still runs the callback. Those
 * run one writer at a time, and pretending to lock would hide that.
 */
final class NamedLock
{
    /** GET_LOCK names are limited to 64 characters, so the caller's name is hashed. */
    private const PREFIX = 'cp_';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws LockTimeoutException when the lock is still held after $waitSeconds
     */
    public function synchronized(string $name, callable $work, int $waitSeconds = 10): mixed
    {
        if (!$this->isDistributed()) {
            return $work();
        }

        $key = self::PREFIX.sha1($name);

        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$key, max(0, $waitSeconds)]) !== 1) {
            throw new LockTimeoutException($name);
        }

        try {
            return $work();
        } finally {
            try {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$key]);
            } catch (\Throwable) {
                // The lock dies with the connection anyway; a failed release must not hide the work's own result or error.
            }
        }
    }

    /**
     * Lock, then run $work inside a database transaction, then commit, then unlock.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function inTransaction(string $name, callable $work, int $waitSeconds = 10): mixed
    {
        return $this->synchronized($name, fn (): mixed => $this->connection->transactional(\Closure::fromCallable($work)), $waitSeconds);
    }

    private function isDistributed(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
