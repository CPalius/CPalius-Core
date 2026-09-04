<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PERF-01: covering index for the Node list query
 * (type + locale + status + published_at DESC via filesort-free prefix).
 */
final class Version20260903180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds idx_node_type_locale_status_published on nodes (type, locale, status, published_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_node_type_locale_status_published ON nodes (type, locale, status, published_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_node_type_locale_status_published ON nodes');
    }
}
