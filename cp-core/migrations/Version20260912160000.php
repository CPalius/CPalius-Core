<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GC2: migrate categories/tags into cp_terms (blog_category / blog_tag) and retarget Node joins.
 * Idempotent — safe to resume after a partial failure (immediate Connection writes).
 */
final class Version20260912160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate Category/Tag rows into Vocabulary terms and remap Node joins.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        $this->ensureVocabulary('blog_category', 'Blog categories', true, 10);
        $this->ensureVocabulary('blog_tag', 'Blog tags', false, 20);

        $catVid = (int) $this->connection->fetchOne(
            'SELECT id FROM cp_vocabularies WHERE machine_name = ?',
            ['blog_category'],
        );
        $tagVid = (int) $this->connection->fetchOne(
            'SELECT id FROM cp_vocabularies WHERE machine_name = ?',
            ['blog_tag'],
        );

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_gc2_cat_map (
            old_id INT NOT NULL,
            term_id INT NOT NULL,
            PRIMARY KEY (old_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS cp_gc2_tag_map (
            old_id INT NOT NULL,
            term_id INT NOT NULL,
            PRIMARY KEY (old_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        if ($sm->tablesExist(['categories']) && $catVid > 0) {
            $this->migrateCategories($catVid);
        }

        if ($sm->tablesExist(['tags']) && $tagVid > 0) {
            $this->migrateTags($tagVid);
        }

        $this->dropForeignKeysPointingAt(['categories', 'tags', 'cp_terms']);

        // Rebuild join tables via INSERT IGNORE to avoid PK collisions during in-place UPDATE.
        if ($sm->tablesExist(['node_category']) && $this->mapHasRows('cp_gc2_cat_map')) {
            $this->rebuildJoinTable(
                'node_category',
                'category_id',
                'cp_gc2_cat_map',
            );
        }

        if ($sm->tablesExist(['node_tag']) && $this->mapHasRows('cp_gc2_tag_map')) {
            $this->rebuildJoinTable(
                'node_tag',
                'tag_id',
                'cp_gc2_tag_map',
            );
        } elseif ($sm->tablesExist(['node_tag']) && $tagVid > 0 && !$sm->tablesExist(['tags'])) {
            // Resume path: tags table already dropped; remap tag_ids that still point at category terms.
            $this->repairOrphanTagJoins($tagVid);
        }

        if ($sm->tablesExist(['nodes']) && $this->mapHasRows('cp_gc2_cat_map')) {
            $this->connection->executeStatement(
                'UPDATE nodes n
                 INNER JOIN cp_gc2_cat_map m ON m.old_id = n.category_id
                 SET n.category_id = m.term_id
                 WHERE n.category_id IS NOT NULL'
            );
        }

        if ($sm->tablesExist(['url_aliases']) && $this->mapHasRows('cp_gc2_cat_map')) {
            $cols = array_map(
                static fn ($c) => $c->getName(),
                $sm->listTableColumns('url_aliases'),
            );
            if (\in_array('target_category_id', $cols, true)) {
                $this->connection->executeStatement(
                    'UPDATE url_aliases ua
                     INNER JOIN cp_gc2_cat_map m ON m.old_id = ua.target_category_id
                     SET ua.target_category_id = m.term_id
                     WHERE ua.target_category_id IS NOT NULL'
                );
            }
        }

        $this->ensureTermForeignKeys($sm);

        if ($sm->tablesExist(['categories'])) {
            $this->connection->executeStatement('DROP TABLE categories');
        }
        if ($sm->tablesExist(['tags'])) {
            $this->connection->executeStatement('DROP TABLE tags');
        }

        $this->connection->executeStatement('DROP TABLE IF EXISTS cp_gc2_cat_map');
        $this->connection->executeStatement('DROP TABLE IF EXISTS cp_gc2_tag_map');
    }

    public function down(Schema $schema): void
    {
        // Irreversible reshape.
    }

    private function migrateCategories(int $catVid): void
    {
        $categories = $this->connection->fetchAllAssociative('SELECT * FROM categories ORDER BY id ASC');
        foreach ($categories as $row) {
            $oldId = (int) $row['id'];
            if ($this->connection->fetchOne('SELECT term_id FROM cp_gc2_cat_map WHERE old_id = ?', [$oldId])) {
                continue;
            }

            // Prefer matching already-inserted term by slug+locale (resume).
            $existingTermId = $this->connection->fetchOne(
                'SELECT id FROM cp_terms WHERE vocabulary_id = ? AND slug = ? AND locale = ?',
                [$catVid, (string) $row['slug'], (string) $row['locale']],
            );
            if ($existingTermId) {
                $this->connection->insert('cp_gc2_cat_map', ['old_id' => $oldId, 'term_id' => (int) $existingTermId]);
                continue;
            }

            $data = '{}';
            if (isset($row['description']) && $row['description'] !== null && $row['description'] !== '') {
                $data = json_encode(['description' => (string) $row['description']], \JSON_THROW_ON_ERROR);
            }

            $now = date('Y-m-d H:i:s');
            $this->connection->insert('cp_terms', [
                'vocabulary_id' => $catVid,
                'parent_id' => null,
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'locale' => (string) $row['locale'],
                'weight' => 0,
                'data' => $data,
                'translation_group_id' => $this->normalizeUuid($row['translation_group_id'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->connection->insert('cp_gc2_cat_map', [
                'old_id' => $oldId,
                'term_id' => (int) $this->connection->lastInsertId(),
            ]);
        }

        foreach ($categories as $row) {
            if ($row['parent_id'] === null) {
                continue;
            }
            $termId = $this->connection->fetchOne('SELECT term_id FROM cp_gc2_cat_map WHERE old_id = ?', [(int) $row['id']]);
            $parentTermId = $this->connection->fetchOne('SELECT term_id FROM cp_gc2_cat_map WHERE old_id = ?', [(int) $row['parent_id']]);
            if ($termId && $parentTermId) {
                $this->connection->update('cp_terms', ['parent_id' => (int) $parentTermId], ['id' => (int) $termId]);
            }
        }
    }

    private function migrateTags(int $tagVid): void
    {
        $tags = $this->connection->fetchAllAssociative('SELECT * FROM tags ORDER BY id ASC');
        foreach ($tags as $row) {
            $oldId = (int) $row['id'];
            if ($this->connection->fetchOne('SELECT term_id FROM cp_gc2_tag_map WHERE old_id = ?', [$oldId])) {
                continue;
            }

            $existingTermId = $this->connection->fetchOne(
                'SELECT id FROM cp_terms WHERE vocabulary_id = ? AND slug = ? AND locale = ?',
                [$tagVid, (string) $row['slug'], (string) $row['locale']],
            );
            if ($existingTermId) {
                $this->connection->insert('cp_gc2_tag_map', ['old_id' => $oldId, 'term_id' => (int) $existingTermId]);
                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->connection->insert('cp_terms', [
                'vocabulary_id' => $tagVid,
                'parent_id' => null,
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'locale' => (string) $row['locale'],
                'weight' => 0,
                'data' => '{}',
                'translation_group_id' => $this->normalizeUuid($row['translation_group_id'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->connection->insert('cp_gc2_tag_map', [
                'old_id' => $oldId,
                'term_id' => (int) $this->connection->lastInsertId(),
            ]);
        }
    }

    private function rebuildJoinTable(string $table, string $fkColumn, string $mapTable): void
    {
        $tmp = $table.'_gc2';
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                node_id INT NOT NULL,
                %s INT NOT NULL,
                PRIMARY KEY (node_id, %s)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
            $tmp,
            $fkColumn,
            $fkColumn,
        ));
        $this->connection->executeStatement(sprintf(
            'INSERT IGNORE INTO %s (node_id, %s)
             SELECT j.node_id, m.term_id
             FROM %s j
             INNER JOIN %s m ON m.old_id = j.%s',
            $tmp,
            $fkColumn,
            $table,
            $mapTable,
            $fkColumn,
        ));
        $this->connection->executeStatement('DROP TABLE '.$table);
        $this->connection->executeStatement(sprintf('RENAME TABLE %s TO %s', $tmp, $table));
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT FK_%s_NODE FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE',
            $table,
            strtoupper($table),
        ));
    }

    private function repairOrphanTagJoins(int $tagVid): void
    {
        // Resume after partial migrate: tags table gone, map gone, but node_tag may still hold old ids.
        $orphanOldIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT nt.tag_id
             FROM node_tag nt
             LEFT JOIN cp_terms t ON t.id = nt.tag_id AND t.vocabulary_id = ?
             WHERE t.id IS NULL
             ORDER BY nt.tag_id ASC',
            [$tagVid],
        );
        $tagTermIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM cp_terms WHERE vocabulary_id = ? ORDER BY id ASC',
            [$tagVid],
        );

        if ($orphanOldIds !== [] && \count($orphanOldIds) <= \count($tagTermIds)) {
            foreach ($orphanOldIds as $i => $oldId) {
                $this->connection->insert('cp_gc2_tag_map', [
                    'old_id' => (int) $oldId,
                    'term_id' => (int) $tagTermIds[$i],
                ]);
            }
            $this->rebuildJoinTable('node_tag', 'tag_id', 'cp_gc2_tag_map');

            return;
        }

        // Fallback: drop joins that do not point at blog_tag terms.
        $this->connection->executeStatement(
            'DELETE nt FROM node_tag nt
             LEFT JOIN cp_terms t ON t.id = nt.tag_id AND t.vocabulary_id = ?
             WHERE t.id IS NULL',
            [$tagVid],
        );
    }

    private function ensureTermForeignKeys($sm): void
    {
        $this->addFkIfMissing('nodes', 'FK_NODE_CATEGORY_TERM', 'category_id', 'cp_terms', 'SET NULL');
        $this->addFkIfMissing('node_category', 'FK_NODE_CAT_TERM', 'category_id', 'cp_terms', 'CASCADE');
        $this->addFkIfMissing('node_tag', 'FK_NODE_TAG_TERM', 'tag_id', 'cp_terms', 'CASCADE');
    }

    private function addFkIfMissing(string $table, string $name, string $column, string $refTable, string $onDelete): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist([$table, $refTable])) {
            return;
        }
        foreach ($sm->listTableForeignKeys($table) as $fk) {
            if ($fk->getName() === $name || ($fk->getForeignTableName() === $refTable && \in_array($column, $fk->getLocalColumns(), true))) {
                return;
            }
        }
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE %s',
            $table,
            $name,
            $column,
            $refTable,
            $onDelete,
        ));
    }

    private function mapHasRows(string $table): bool
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist([$table])) {
            return false;
        }

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table) > 0;
    }

    private function ensureVocabulary(string $machineName, string $label, bool $hierarchical, int $weight): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT id FROM cp_vocabularies WHERE machine_name = ?',
            [$machineName],
        );
        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->insert('cp_vocabularies', [
            'machine_name' => $machineName,
            'label' => $label,
            'description' => null,
            'hierarchical' => $hierarchical ? 1 : 0,
            'weight' => $weight,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param list<string> $targets
     */
    private function dropForeignKeysPointingAt(array $targets): void
    {
        $sm = $this->connection->createSchemaManager();
        foreach (['nodes', 'node_category', 'node_tag', 'url_aliases'] as $table) {
            if (!$sm->tablesExist([$table])) {
                continue;
            }
            foreach ($sm->listTableForeignKeys($table) as $fk) {
                if (!\in_array($fk->getForeignTableName(), $targets, true)) {
                    continue;
                }
                $this->connection->executeStatement(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $table,
                    $fk->getName(),
                ));
            }
        }
    }

    private function normalizeUuid(mixed $raw): ?string
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        if (\strlen($raw) === 16) {
            return sprintf(
                '%s-%s-%s-%s-%s',
                bin2hex(substr($raw, 0, 4)),
                bin2hex(substr($raw, 4, 2)),
                bin2hex(substr($raw, 6, 2)),
                bin2hex(substr($raw, 8, 2)),
                bin2hex(substr($raw, 10, 6)),
            );
        }

        return $raw;
    }
}
