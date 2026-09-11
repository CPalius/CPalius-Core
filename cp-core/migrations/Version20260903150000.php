<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 4 — replaces plain translation_group_id indexes with UNIQUE(translation_group_id, locale) on four tables.
 * Enforces at most one row per locale per translation group; multiple NULL groups remain allowed.
 */
final class Version20260903150000 extends AbstractMigration
{
    /**
     * @var array<string, array{0: string, 1: string}> table => [old index, new unique constraint]
     */
    private const TABLES = [
        'categories' => ['idx_category_translation_group', 'uniq_category_translation_group_locale'],
        'tags' => ['idx_tag_translation_group', 'uniq_tag_translation_group_locale'],
        'menu_items' => ['idx_menu_item_translation_group', 'uniq_menu_item_translation_group_locale'],
        'forum_sections' => ['idx_forum_section_translation_group', 'uniq_forum_section_translation_group_locale'],
    ];

    public function getDescription(): string
    {
        return 'FAZ 4: replaces the translation_group_id index with UNIQUE(translation_group_id, locale) on categories, tags, menu_items and forum_sections.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => [$indexName, $uniqueName]) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $table));
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (translation_group_id, locale)',
                $uniqueName,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => [$indexName, $uniqueName]) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $uniqueName, $table));
            $this->addSql(sprintf('CREATE INDEX %s ON %s (translation_group_id)', $indexName, $table));
        }
    }
}
