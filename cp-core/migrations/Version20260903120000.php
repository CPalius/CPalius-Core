<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3 — adds nullable BINARY(16) translation_group_id to categories, tags, menu_items, forum_sections.
 * Search index only here; strict UNIQUE(translation_group_id, locale) comes in Version20260903150000.
 */
final class Version20260903120000 extends AbstractMigration
{
    /**
     * @var array<string, string> table => index name
     */
    private const TABLES = [
        'categories' => 'idx_category_translation_group',
        'tags' => 'idx_tag_translation_group',
        'menu_items' => 'idx_menu_item_translation_group',
        'forum_sections' => 'idx_forum_section_translation_group',
    ];

    public function getDescription(): string
    {
        return 'FAZ 3: adds translation_group_id (UUID v7, BINARY(16)) to categories, tags, menu_items and forum_sections.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $indexName) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD translation_group_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'',
                $table,
            ));

            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (translation_group_id)',
                $indexName,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $indexName) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP translation_group_id', $table));
        }
    }
}
