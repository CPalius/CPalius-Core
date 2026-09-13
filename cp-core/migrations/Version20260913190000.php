<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixes cp_terms.translation_group_id: CHAR(36) -> BINARY(16).
 *
 * Version20260911120000 created the column as CHAR(36), but TranslatableTrait
 * maps it with UuidType, and on a platform without a native GUID type Doctrine
 * writes Uuid::toBinary() — 16 raw bytes. Writing those into a CHAR(36) utf8mb4
 * column does not silently truncate, it aborts the request:
 *
 *   SQLSTATE[22007]: Incorrect string value: '\xA0\x9CG\x030\007F...'
 *   for column cp_terms.translation_group_id
 *
 * Every other translation_group_id in the schema (nodes, menu_items,
 * forum_sections, roadmap_entries) is already BINARY(16); this column was the
 * odd one out. The bug stayed invisible while every row held NULL and surfaced
 * the first time a term was assigned to a translation group.
 *
 * Existing dashed-UUID strings are converted rather than dropped, so an
 * installation that somehow wrote text values keeps its groups.
 */
final class Version20260913190000 extends AbstractMigration
{
    private const INDEX = 'uniq_term_translation_group_locale';

    public function getDescription(): string
    {
        return 'cp_terms.translation_group_id becomes BINARY(16) to match UuidType.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['cp_terms'])) {
            return;
        }

        $columns = $sm->listTableColumns('cp_terms');
        if (!isset($columns['translation_group_id'])) {
            return;
        }

        // Already BINARY(16): nothing to do (fresh install, or re-run).
        if ($this->isBinary16()) {
            return;
        }

        /*
         * A straight MODIFY would reinterpret the 36-character text as bytes and
         * keep the first 16, destroying every value. The bytes are built
         * explicitly in a temporary column instead, placed where the original
         * sat so column order survives.
         */
        $this->addSql("ALTER TABLE cp_terms ADD tg_bin_tmp BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)' AFTER data");

        $this->addSql(
            "UPDATE cp_terms
                SET tg_bin_tmp = UNHEX(REPLACE(translation_group_id, '-', ''))
              WHERE translation_group_id IS NOT NULL
                AND CHAR_LENGTH(REPLACE(translation_group_id, '-', '')) = 32"
        );

        // The unique index covers the column, so it has to go before the drop.
        if ($this->indexExists()) {
            $this->addSql('DROP INDEX '.self::INDEX.' ON cp_terms');
        }

        $this->addSql('ALTER TABLE cp_terms DROP translation_group_id');
        $this->addSql(
            "ALTER TABLE cp_terms
              CHANGE tg_bin_tmp translation_group_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'"
        );
        $this->addSql('CREATE UNIQUE INDEX '.self::INDEX.' ON cp_terms (translation_group_id, locale)');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['cp_terms'])) {
            return;
        }

        if (!$this->isBinary16()) {
            return;
        }

        $this->addSql("ALTER TABLE cp_terms ADD tg_txt_tmp CHAR(36) DEFAULT NULL COMMENT '(DC2Type:uuid)' AFTER data");
        $this->addSql(
            "UPDATE cp_terms
                SET tg_txt_tmp = LOWER(CONCAT_WS('-',
                    HEX(SUBSTRING(translation_group_id, 1, 4)),
                    HEX(SUBSTRING(translation_group_id, 5, 2)),
                    HEX(SUBSTRING(translation_group_id, 7, 2)),
                    HEX(SUBSTRING(translation_group_id, 9, 2)),
                    HEX(SUBSTRING(translation_group_id, 11, 6))))
              WHERE translation_group_id IS NOT NULL"
        );

        if ($this->indexExists()) {
            $this->addSql('DROP INDEX '.self::INDEX.' ON cp_terms');
        }

        $this->addSql('ALTER TABLE cp_terms DROP translation_group_id');
        $this->addSql(
            "ALTER TABLE cp_terms
              CHANGE tg_txt_tmp translation_group_id CHAR(36) DEFAULT NULL COMMENT '(DC2Type:uuid)'"
        );
        $this->addSql('CREATE UNIQUE INDEX '.self::INDEX.' ON cp_terms (translation_group_id, locale)');
    }

    /**
     * listTableColumns() reports both CHAR(36) and BINARY(16) as a string type,
     * so the distinction is read from the raw column definition instead.
     */
    private function isBinary16(): bool
    {
        $type = $this->connection->fetchOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?',
            ['cp_terms', 'translation_group_id'],
        );

        return \is_string($type) && str_contains(strtolower($type), 'binary(16)');
    }

    private function indexExists(): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?',
            ['cp_terms', self::INDEX],
        ) > 0;
    }
}
