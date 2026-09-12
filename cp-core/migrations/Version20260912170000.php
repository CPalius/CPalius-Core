<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T3.4 Phase A: the migration map that makes imports idempotent and rollback exact.
 *
 * The unique key on (migration_id, source_id) is the guarantee itself — it is
 * the database, not the runner's bookkeeping, that stops a row being imported
 * twice when two runs overlap.
 */
final class Version20260912170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cp_migration_map (Migrate API source id -> destination id map).';
    }

    /**
     * MySQL commits implicitly on DDL, which invalidates the savepoint the
     * migration runner opened around this migration; releasing it afterwards
     * then fails with "SAVEPOINT DOCTRINE_2 does not exist" even though the
     * table was created and the version was recorded. The operator is told the
     * update failed when it did not — so the wrapper is declined rather than
     * left to complain about work MySQL had already committed.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_migration_map (
            id INT AUTO_INCREMENT NOT NULL,
            migration_id VARCHAR(128) NOT NULL,
            source_id VARCHAR(191) NOT NULL,
            checksum VARCHAR(64) NOT NULL,
            destination_type VARCHAR(64) NOT NULL,
            destination_id VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_migration_source (migration_id, source_id),
            INDEX idx_migration_map_migration (migration_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS cp_migration_map');
    }
}
