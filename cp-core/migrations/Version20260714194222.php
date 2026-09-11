<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recreated no-op placeholder after the original file was lost; schema already matches (see Version20260714195853).
 */
final class Version20260714194222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial creation of categories and nodes tables (recreated because the file was lost; no-op).';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
