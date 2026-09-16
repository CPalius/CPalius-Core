<?php

declare(strict_types=1);

/**
 * Emits the v2 schema migration from table-map.php.
 *
 * Generated once and then left alone: a migration is history, so it carries its
 * table names literally rather than reading the map at runtime. If the map
 * changes later, the map changes — this file does not.
 *
 * Usage: php tools/schema/generate-migration.php > cp-core/migrations/VersionX.php
 */

$map = require __DIR__ . '/table-map.php';
$version = $argv[1] ?? '20260916120000';
$collate = $map['collation']['collate'];
$charset = $map['collation']['charset'];

$renamePairs = '';
foreach ($map['renames'] as $old => $new) {
    $renamePairs .= sprintf("        '%s' => '%s',\n", $old, $new);
}

$allTables = array_merge(
    array_values($map['renames']),
    array_keys($map['merges']),
    $map['unchanged'],
    $map['framework'],
);
sort($allTables);

$tableList = '';
foreach ($allTables as $t) {
    $tableList .= sprintf("        '%s',\n", $t);
}

$reverse = '';
foreach ($map['renames'] as $old => $new) {
    $reverse .= sprintf("        '%s' => '%s',\n", $new, $old);
}

echo <<<PHP
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\\DBAL\\Schema\\Schema;
use Doctrine\\Migrations\\AbstractMigration;

/**
 * Schema v2: one prefix, one collation, and two tables that were each written
 * twice folded into one.
 *
 * Three things were wrong with the schema this replaces, and only the first is
 * cosmetic:
 *
 *   1. Nine core tables predated the cp_ convention and never got it, while
 *      module tables used whatever prefix their author picked (forum_, blog_).
 *      Nothing in the name said who owned a table.
 *
 *   2. The database carried three collations — utf8mb4_0900_ai_ci on the nine
 *      oldest tables, utf8mb4_unicode_ci on the other 56, utf8mb4_general_ci on
 *      doctrine_migration_versions. Joining a varchar across that boundary
 *      raises "Illegal mix of collations", so the split was a live bug waiting
 *      for the first query that crossed it.
 *
 *   3. forum_post_likes / forum_post_dislikes and forum_topic_views /
 *      forum_topic_watches were the same four columns written out twice. The
 *      like/dislike pair also had no way to enforce that a member holds only
 *      one verdict: exclusivity lived in ForumTopicService, which deletes the
 *      opposing row before inserting, and two concurrent requests could leave
 *      both rows standing.
 *
 * Everything here is guarded on information_schema, so a site that has already
 * taken part of this migration — or a fresh install that never had the old
 * names — passes through the parts that no longer apply instead of failing.
 *
 * Merge conflicts resolve by recency: a member who somehow holds both a like
 * and a dislike on one post keeps whichever they did last.
 */
final class Version{$version} extends AbstractMigration
{
    /**
     * Old name => new name. Literal rather than read from tools/schema, because
     * this migration has to keep describing the move it made even after the map
     * moves on.
     */
    private const RENAMES = [
{$renamePairs}    ];

    /** Every table the v2 schema expects to exist, for the collation pass. */
    private const V2_TABLES = [
{$tableList}    ];

    private const REVERSE = [
{$reverse}    ];

    public function getDescription(): string
    {
        return 'Schema v2: cp_ prefix everywhere, single collation, forum vote and topic-state merges.';
    }

    /**
     * MySQL commits implicitly on DDL, which invalidates the savepoint the
     * migration runner opened around this migration; releasing it afterwards
     * then fails even though the work succeeded.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema \$schema): void
    {
        foreach (self::RENAMES as \$old => \$new) {
            \$this->renameIfNeeded(\$old, \$new);
        }

        \$this->renameGeneratedIndexes();
        \$this->mergePostVotes();
        \$this->mergeTopicUserState();
        \$this->normaliseCollation();
    }

    public function down(Schema \$schema): void
    {
        // The merges are not reversed. Splitting cp_forum_post_votes back into
        // two tables is mechanical, but a member who switched sides after the
        // upgrade has one row where the old schema would have wanted a
        // different one, and there is no honest way to invent the difference.
        \$this->throwIrreversibleMigrationException(
            'Schema v2 renames can be rolled back, the forum merges cannot. Restore from the pre-upgrade backup instead.'
        );
    }

    /**
     * Doctrine names an index it generated after a hash of the table it sits on,
     * so renaming the table leaves the index answering to the old table's hash
     * and every doctrine:schema:validate from here on reports drift.
     *
     * Only the five indexes whose names Doctrine derived itself are listed. The
     * ones migrations named by hand (fk_node_cat_term, idx_forum_draft_topic and
     * friends) were already reported as drift before this migration existed and
     * are left alone — fixing them is a separate decision, not a side effect of
     * a rename.
     *
     * Each rename is guarded: an installation whose history differs may not have
     * the old name, and MySQL errors rather than shrugging on a missing index.
     */
    private function renameGeneratedIndexes(): void
    {
        \$renames = [
            ['cp_nodes', 'idx_1d3d05fcf675f31b', 'IDX_F1E9E0D9F675F31B'],
            ['cp_nodes', 'idx_1d3d05fc12469de2', 'IDX_F1E9E0D912469DE2'],
            ['cp_node_field_index', 'idx_ebdf5a13460d9fd7', 'IDX_504B9FA8460D9FD7'],
            ['cp_menu_items', 'idx_70b2ca2accd7e912', 'IDX_E079FA58CCD7E912'],
            ['cp_menu_items', 'idx_70b2ca2a727aca70', 'IDX_E079FA58727ACA70'],
        ];

        foreach (\$renames as [\$table, \$old, \$new]) {
            if (!\$this->tableExists(\$table)) {
                continue;
            }

            \$has = (int) \$this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [\$table, \$old],
            );

            if (\$has === 0) {
                continue;
            }

            \$this->connection->executeStatement(sprintf(
                'ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`',
                \$table,
                \$old,
                \$new,
            ));
        }
    }

    private function tableExists(string \$table): bool
    {
        return (int) \$this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [\$table],
        ) > 0;
    }

    private function renameIfNeeded(string \$old, string \$new): void
    {
        if (!\$this->tableExists(\$old) || \$this->tableExists(\$new)) {
            return;
        }

        \$this->connection->executeStatement(sprintf('RENAME TABLE `%s` TO `%s`', \$old, \$new));
    }

    /**
     * forum_post_likes + forum_post_dislikes -> cp_forum_post_votes.
     *
     * Likes land first, then dislikes, and the ON DUPLICATE clause keeps the
     * later of the two when a member holds both — the row that reflects what
     * they most recently decided.
     *
     * VALUES() rather than the newer row-alias syntax: MariaDB, which is what
     * cpalius.com runs, does not accept "AS new".
     */
    private function mergePostVotes(): void
    {
        if (\$this->tableExists('cp_forum_post_votes')) {
            return;
        }

        \$this->connection->executeStatement('CREATE TABLE cp_forum_post_votes (
            id INT AUTO_INCREMENT NOT NULL,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            vote SMALLINT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cp_forum_post_vote (post_id, user_id),
            KEY idx_cp_forum_post_vote_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET {$charset} COLLATE {$collate} ENGINE = InnoDB');

        if (\$this->tableExists('forum_post_likes')) {
            \$this->connection->executeStatement(
                'INSERT INTO cp_forum_post_votes (post_id, user_id, vote, created_at)
                 SELECT post_id, user_id, 1, created_at FROM forum_post_likes'
            );
        }

        if (\$this->tableExists('forum_post_dislikes')) {
            \$this->connection->executeStatement(
                'INSERT INTO cp_forum_post_votes (post_id, user_id, vote, created_at)
                 SELECT post_id, user_id, -1, created_at FROM forum_post_dislikes
                 ON DUPLICATE KEY UPDATE
                     vote = IF(VALUES(created_at) > cp_forum_post_votes.created_at, VALUES(vote), cp_forum_post_votes.vote),
                     created_at = GREATEST(cp_forum_post_votes.created_at, VALUES(created_at))'
            );
        }

        \$this->connection->executeStatement('ALTER TABLE cp_forum_post_votes
            ADD CONSTRAINT fk_cp_forum_post_vote_post FOREIGN KEY (post_id) REFERENCES cp_forum_posts (id) ON DELETE CASCADE');
        \$this->connection->executeStatement('ALTER TABLE cp_forum_post_votes
            ADD CONSTRAINT fk_cp_forum_post_vote_user FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE');

        \$this->dropIfExists('forum_post_likes');
        \$this->dropIfExists('forum_post_dislikes');
    }

    /**
     * forum_topic_views + forum_topic_watches -> cp_forum_topic_user_state.
     *
     * No conflict to resolve: the two sources fill different columns of the
     * same row, so a member who both read and watched a thread ends up with one
     * record carrying both facts.
     */
    private function mergeTopicUserState(): void
    {
        if (\$this->tableExists('cp_forum_topic_user_state')) {
            return;
        }

        \$this->connection->executeStatement('CREATE TABLE cp_forum_topic_user_state (
            id INT AUTO_INCREMENT NOT NULL,
            topic_id INT NOT NULL,
            user_id INT NOT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            watching_since DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_cp_forum_topic_user_state (topic_id, user_id),
            KEY idx_cp_forum_tus_topic (topic_id),
            KEY idx_cp_forum_tus_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET {$charset} COLLATE {$collate} ENGINE = InnoDB');

        if (\$this->tableExists('forum_topic_views')) {
            \$this->connection->executeStatement(
                'INSERT INTO cp_forum_topic_user_state (topic_id, user_id, last_seen_at, watching_since)
                 SELECT topic_id, user_id, last_seen_at, NULL FROM forum_topic_views'
            );
        }

        if (\$this->tableExists('forum_topic_watches')) {
            \$this->connection->executeStatement(
                'INSERT INTO cp_forum_topic_user_state (topic_id, user_id, last_seen_at, watching_since)
                 SELECT topic_id, user_id, NULL, created_at FROM forum_topic_watches
                 ON DUPLICATE KEY UPDATE watching_since = VALUES(watching_since)'
            );
        }

        \$this->connection->executeStatement('ALTER TABLE cp_forum_topic_user_state
            ADD CONSTRAINT fk_cp_forum_tus_topic FOREIGN KEY (topic_id) REFERENCES cp_forum_topics (id) ON DELETE CASCADE');
        \$this->connection->executeStatement('ALTER TABLE cp_forum_topic_user_state
            ADD CONSTRAINT fk_cp_forum_tus_user FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE');

        \$this->dropIfExists('forum_topic_views');
        \$this->dropIfExists('forum_topic_watches');
    }

    private function dropIfExists(string \$table): void
    {
        if (\$this->tableExists(\$table)) {
            \$this->connection->executeStatement(sprintf('DROP TABLE `%s`', \$table));
        }
    }

    /**
     * One collation for the whole database.
     *
     * Foreign key checks go off for the duration: CONVERT TO rebuilds each
     * table in turn, and a table converted before the one it references would
     * momentarily disagree with it on charset.
     */
    private function normaliseCollation(): void
    {
        \$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::V2_TABLES as \$table) {
                if (!\$this->tableExists(\$table)) {
                    continue;
                }

                \$current = \$this->connection->fetchOne(
                    'SELECT TABLE_COLLATION FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [\$table],
                );

                if (\$current === '{$collate}') {
                    continue;
                }

                \$this->connection->executeStatement(sprintf(
                    'ALTER TABLE `%s` CONVERT TO CHARACTER SET {$charset} COLLATE {$collate}',
                    \$table,
                ));
            }
        } finally {
            \$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}

PHP;
