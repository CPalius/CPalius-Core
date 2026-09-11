<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T2.4 defaults: seed path patterns so a new post actually gets
 * /blog/{year}/{title} without an admin having to visit AACP first
 * (the roadmap evidence). Idempotent — never overwrites an existing row.
 */
final class Version20260911230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed default path alias patterns for post and page bundles.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['cp_path_alias_patterns'])) {
            return;
        }

        $this->addSql("INSERT INTO cp_path_alias_patterns (entity_type, bundle, pattern, enabled, created_at, updated_at)
             SELECT 'node', 'post', '/blog/[node:created:Y]/[node:title]', 1, NOW(), NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM cp_path_alias_patterns p WHERE p.entity_type = 'node' AND p.bundle = 'post'
             )");

        $this->addSql("INSERT INTO cp_path_alias_patterns (entity_type, bundle, pattern, enabled, created_at, updated_at)
             SELECT 'node', 'page', '/[node:title]', 1, NOW(), NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM cp_path_alias_patterns p WHERE p.entity_type = 'node' AND p.bundle = 'page'
             )");
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['cp_path_alias_patterns'])) {
            return;
        }

        $this->addSql("DELETE FROM cp_path_alias_patterns WHERE entity_type = 'node' AND bundle IN ('post', 'page') AND pattern IN ('/blog/[node:created:Y]/[node:title]', '/[node:title]')");
    }
}
