<?php

declare(strict_types=1);

/**
 * Completes the 2.0.0 schema migration on a site that took the files but not
 * the migration, and is now down with "Table 'cp_users' doesn't exist".
 *
 * How a site ends up here: AACPUpdateController::upgrade() writes the new files
 * and then renders the updates screen. Migrations are deliberately left to the
 * next request, but that screen is itself served by the new code against the old
 * database, so on 2.0.0 — the first release that renames tables — it dies before
 * the operator can ever reach the button that would have run them. The panel is
 * unreachable, so nothing inside the application can fix it.
 *
 * This script therefore uses PDO directly: no Symfony, no container, no Doctrine.
 * It performs exactly what Version20260916120000 performs, in the same order,
 * with the same guards, and records the migration as executed so the normal
 * runner does not try again.
 *
 * USE
 *   1. Upload to public/ by FTP.
 *   2. Open https://<site>/cp-2.0.0-recover.php?token=<AACP_RECOVERY_TOKEN>
 *      (the value from your .env; add &dry=1 first to see the plan without
 *      touching anything).
 *   3. Empty cp-core/var/cache/prod/ afterwards.
 *   4. DELETE THIS FILE.
 *
 * Every step is guarded, so running it twice is harmless.
 */

const RENAMES = [
    'assets' => 'cp_assets',
    'nodes' => 'cp_nodes',
    'node_field_index' => 'cp_node_field_index',
    'users' => 'cp_users',
    'url_aliases' => 'cp_path_aliases',
    'node_category' => 'cp_node_categories',
    'node_tag' => 'cp_node_tags',
    'menus' => 'cp_menu_menus',
    'menu_items' => 'cp_menu_items',
    'blog_comments' => 'cp_blog_comments',
    'roadmap_entries' => 'cp_roadmap_entries',
    'forum_bans' => 'cp_forum_bans',
    'forum_censor_words' => 'cp_forum_censor_words',
    'forum_drafts' => 'cp_forum_drafts',
    'forum_link_previews' => 'cp_forum_link_previews',
    'forum_moderation_logs' => 'cp_forum_moderation_logs',
    'forum_node_permissions' => 'cp_forum_node_permissions',
    'forum_polls' => 'cp_forum_polls',
    'forum_poll_options' => 'cp_forum_poll_options',
    'forum_poll_votes' => 'cp_forum_poll_votes',
    'forum_posts' => 'cp_forum_posts',
    'forum_post_attachments' => 'cp_forum_post_attachments',
    'forum_post_reports' => 'cp_forum_post_reports',
    'forum_read_markers' => 'cp_forum_read_markers',
    'forum_prefix_sections' => 'cp_forum_prefix_sections',
    'forum_presence' => 'cp_forum_presence',
    'forum_sections' => 'cp_forum_sections',
    'forum_topics' => 'cp_forum_topics',
    'forum_topic_prefixes' => 'cp_forum_topic_prefixes',
    'forum_user_ranks' => 'cp_forum_user_ranks',
    'forum_user_reputations' => 'cp_forum_user_reputations',
];

const INDEX_RENAMES = [
    ['cp_nodes', 'idx_1d3d05fcf675f31b', 'IDX_F1E9E0D9F675F31B'],
    ['cp_nodes', 'idx_1d3d05fc12469de2', 'IDX_F1E9E0D912469DE2'],
    ['cp_node_field_index', 'idx_ebdf5a13460d9fd7', 'IDX_504B9FA8460D9FD7'],
    ['cp_menu_items', 'idx_70b2ca2accd7e912', 'IDX_E079FA58CCD7E912'],
    ['cp_menu_items', 'idx_70b2ca2a727aca70', 'IDX_E079FA58727ACA70'],
];

const MIGRATION_VERSION = 'DoctrineMigrations\\Version20260916120000';
const COLLATION = 'utf8mb4_unicode_ci';

header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);

/** Reads one key out of .env / .env.local without booting Symfony. */
function envValue(string $root, string $key): ?string
{
    foreach (['/.env.local', '/.env'] as $file) {
        if (!is_file($root . $file)) {
            continue;
        }
        foreach (file($root . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_starts_with($line, $key . '=')) {
                continue;
            }
            $v = trim(substr($line, strlen($key) + 1));
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                $v = substr($v, 1, -1);
            }

            return $v;
        }
    }

    return null;
}

$expected = envValue($root, 'AACP_RECOVERY_TOKEN');
$given = (string) ($_GET['token'] ?? '');

if ($expected === null || $expected === '') {
    http_response_code(500);
    exit("AACP_RECOVERY_TOKEN is not set in .env — refusing to run.\n");
}
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    exit("Bad or missing token.\n");
}

$dsnUrl = envValue($root, 'DATABASE_URL');
if ($dsnUrl === null) {
    http_response_code(500);
    exit("DATABASE_URL not found in .env.\n");
}

$p = parse_url($dsnUrl);
if ($p === false || !isset($p['host'], $p['path'])) {
    http_response_code(500);
    exit("DATABASE_URL could not be parsed.\n");
}

$dbName = ltrim($p['path'], '/');
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p['host'], $p['port'] ?? 3306, $dbName);

try {
    $pdo = new PDO($dsn, rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not connect: ' . $e->getMessage() . "\n");
}

$dry = isset($_GET['dry']);
$did = [];
$note = static function (string $s) use (&$did): void {
    $did[] = $s;
};

$tableExists = static function (string $t) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$t]);

    return (int) $s->fetchColumn() > 0;
};

$run = static function (string $sql) use ($pdo, $dry): void {
    if (!$dry) {
        $pdo->exec($sql);
    }
};

echo "CPalius 2.0.0 schema recovery\n";
echo 'Database: ' . $dbName . "\n";
echo $dry ? "MODE: dry run, nothing will be written\n\n" : "MODE: applying\n\n";

try {
    // 1. Table renames.
    foreach (RENAMES as $old => $new) {
        if ($tableExists($old) && !$tableExists($new)) {
            $run(sprintf('RENAME TABLE `%s` TO `%s`', $old, $new));
            $note("renamed {$old} -> {$new}");
        }
    }

    // 2. Index names Doctrine derived from the old table names.
    foreach (INDEX_RENAMES as [$table, $oldIdx, $newIdx]) {
        if (!$tableExists($table)) {
            continue;
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $s->execute([$table, $oldIdx]);
        if ((int) $s->fetchColumn() > 0) {
            $run(sprintf('ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`', $table, $oldIdx, $newIdx));
            $note("index {$table}.{$oldIdx} -> {$newIdx}");
        }
    }

    // 3. Likes + dislikes -> votes.
    if (!$tableExists('cp_forum_post_votes')) {
        $run('CREATE TABLE cp_forum_post_votes (
            id INT AUTO_INCREMENT NOT NULL,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            vote SMALLINT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cp_forum_post_vote (post_id, user_id),
            KEY idx_cp_forum_post_vote_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE ' . COLLATION . ' ENGINE = InnoDB');
        $note('created cp_forum_post_votes');

        if ($tableExists('forum_post_likes')) {
            $run('INSERT INTO cp_forum_post_votes (post_id, user_id, vote, created_at)
                  SELECT post_id, user_id, 1, created_at FROM forum_post_likes');
            $note('copied forum_post_likes');
        }
        if ($tableExists('forum_post_dislikes')) {
            $run('INSERT INTO cp_forum_post_votes (post_id, user_id, vote, created_at)
                  SELECT post_id, user_id, -1, created_at FROM forum_post_dislikes
                  ON DUPLICATE KEY UPDATE
                      vote = IF(VALUES(created_at) > cp_forum_post_votes.created_at, VALUES(vote), cp_forum_post_votes.vote),
                      created_at = GREATEST(cp_forum_post_votes.created_at, VALUES(created_at))');
            $note('copied forum_post_dislikes');
        }

        $run('ALTER TABLE cp_forum_post_votes ADD CONSTRAINT fk_cp_forum_post_vote_post FOREIGN KEY (post_id) REFERENCES cp_forum_posts (id) ON DELETE CASCADE');
        $run('ALTER TABLE cp_forum_post_votes ADD CONSTRAINT fk_cp_forum_post_vote_user FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE');

        foreach (['forum_post_likes', 'forum_post_dislikes'] as $t) {
            if ($tableExists($t)) {
                $run(sprintf('DROP TABLE `%s`', $t));
                $note("dropped {$t}");
            }
        }
    }

    // 4. Views + watches -> one per-member-per-thread row.
    if (!$tableExists('cp_forum_topic_user_state')) {
        $run('CREATE TABLE cp_forum_topic_user_state (
            id INT AUTO_INCREMENT NOT NULL,
            topic_id INT NOT NULL,
            user_id INT NOT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            watching_since DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_cp_forum_topic_user_state (topic_id, user_id),
            KEY idx_cp_forum_tus_topic (topic_id),
            KEY idx_cp_forum_tus_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE ' . COLLATION . ' ENGINE = InnoDB');
        $note('created cp_forum_topic_user_state');

        if ($tableExists('forum_topic_views')) {
            $run('INSERT INTO cp_forum_topic_user_state (topic_id, user_id, last_seen_at, watching_since)
                  SELECT topic_id, user_id, last_seen_at, NULL FROM forum_topic_views');
            $note('copied forum_topic_views');
        }
        if ($tableExists('forum_topic_watches')) {
            $run('INSERT INTO cp_forum_topic_user_state (topic_id, user_id, last_seen_at, watching_since)
                  SELECT topic_id, user_id, NULL, created_at FROM forum_topic_watches
                  ON DUPLICATE KEY UPDATE watching_since = VALUES(watching_since)');
            $note('copied forum_topic_watches');
        }

        $run('ALTER TABLE cp_forum_topic_user_state ADD CONSTRAINT fk_cp_forum_tus_topic FOREIGN KEY (topic_id) REFERENCES cp_forum_topics (id) ON DELETE CASCADE');
        $run('ALTER TABLE cp_forum_topic_user_state ADD CONSTRAINT fk_cp_forum_tus_user FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE');

        foreach (['forum_topic_views', 'forum_topic_watches'] as $t) {
            if ($tableExists($t)) {
                $run(sprintf('DROP TABLE `%s`', $t));
                $note("dropped {$t}");
            }
        }
    }

    // 5. One collation for every table.
    $run('SET FOREIGN_KEY_CHECKS = 0');
    $rows = $pdo->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $converted = 0;
    foreach ($rows as $t => $coll) {
        if ($coll !== COLLATION) {
            $run(sprintf('ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE %s', $t, COLLATION));
            ++$converted;
        }
    }
    $run('SET FOREIGN_KEY_CHECKS = 1');
    if ($converted > 0) {
        $note("converted {$converted} tables to " . COLLATION);
    }

    // 6. Tell Doctrine the migration is done, so the runner does not repeat it.
    if ($tableExists('doctrine_migration_versions')) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?');
        $s->execute([MIGRATION_VERSION]);
        if ((int) $s->fetchColumn() === 0 && !$dry) {
            $i = $pdo->prepare('INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, NOW(), 0)');
            $i->execute([MIGRATION_VERSION]);
            $note('recorded ' . MIGRATION_VERSION);
        } elseif ((int) $s->fetchColumn() === 0) {
            $note('would record ' . MIGRATION_VERSION);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n\n";
    echo "Steps completed before the failure:\n";
    foreach ($did as $line) {
        echo '  - ' . $line . "\n";
    }
    echo "\nEvery step is guarded; fix the cause and run this again.\n";
    exit;
}

if ($did === []) {
    echo "Nothing to do — the schema is already at 2.0.0.\n";
} else {
    echo "Done:\n";
    foreach ($did as $line) {
        echo '  - ' . $line . "\n";
    }
}

echo "\nNext:\n";
echo "  1. Empty cp-core/var/cache/prod/ (keep the folder).\n";
echo "  2. Clear the Doctrine Redis pools if this site uses Redis.\n";
echo "  3. DELETE THIS FILE.\n";
