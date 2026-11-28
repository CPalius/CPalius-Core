<?php

declare(strict_types=1);

namespace App\Core\Update\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Events;

/**
 * MySQL/MariaDB commit implicitly on DDL. A transactional migration then has
 * nothing left to commit, Doctrine Migrations skips the commit, and DBAL's
 * nesting level stays one higher per migration. The web installer and
 * cp:update run migrations and update hooks in one process, so the first hook
 * that touched a transaction failed with "SAVEPOINT DOCTRINE_n does not exist".
 *
 * After a migrate run, a level DBAL still counts while the server has no open
 * transaction is only bookkeeping: close() drops it and the connection
 * reconnects lazily on the next query.
 */
#[AsDoctrineListener(event: Events::onMigrationsMigrated)]
final class MigrationTransactionReset
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function onMigrationsMigrated(): void
    {
        if (!$this->connection->isTransactionActive()) {
            return;
        }

        $native = $this->connection->getNativeConnection();
        if ($native instanceof \PDO && $native->inTransaction()) {
            return;
        }

        $this->connection->close();
    }
}
