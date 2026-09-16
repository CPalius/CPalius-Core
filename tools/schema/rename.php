<?php

declare(strict_types=1);

/**
 * Applies the v2 table names to the codebase.
 *
 * Table names are rewritten only where they are unambiguously table names:
 * inside ORM\Table / ORM\JoinTable attributes, in DBAL insert/update/delete
 * calls, and after FROM / JOIN / INTO / UPDATE / TABLE in SQL strings.
 *
 * Blanket string replacement is not an option here — 'users', 'assets' and
 * 'nodes' are ordinary array keys, route names and template variables all over
 * this codebase, and replacing those would break things in ways no test would
 * obviously catch. Anything the context patterns do not match is reported at
 * the end as a leftover for a human to look at, rather than guessed.
 *
 * Migrations are never touched: they are the history of how the database got
 * here, and rewriting them would make old migrations describe tables that did
 * not exist when they ran.
 *
 * Usage:
 *   php tools/schema/rename.php            # dry run, prints every change
 *   php tools/schema/rename.php --write    # apply
 */

$root = dirname(__DIR__, 2);
$map = require __DIR__ . '/table-map.php';

$write = in_array('--write', $argv, true);

/** @var array<string, string> $renames old table name => new table name */
$renames = $map['renames'];

// Merged sources have no single new name; the rewriter must not guess at them.
$mergedSources = [];
foreach ($map['merges'] as $target => $spec) {
    foreach (array_keys($spec['sources']) as $old) {
        $mergedSources[$old] = $target;
    }
}

$scanDirs = [
    'cp-core/src',
    'cp-core/tests',
    'cp-content',
    'cp-core/config',
];

/**
 * Files where a table name is not a reference to our table.
 *
 * Every one of these was found by reading the dry run's leftover report, not
 * guessed: the security tests feed "DROP TABLE users" and "UNION SELECT
 * password FROM users" to the threat analyser as attack strings, and renaming
 * the table inside a payload quietly weakens the test while it keeps passing.
 * The Importer reads Joomla, MyBB and XenForo databases, where `users` is their
 * table and has nothing to do with ours.
 *
 * @var array<string, string> path fragment => why it is excluded
 */
$excluded = [
    'cp-core/tests/Unit/Core/Security/ThreatAnalyzerTest.php' => 'SQL injection saldiri yukleri',
    'cp-core/tests/Unit/Core/Security/RequestGuardSubscriberTest.php' => 'SQL injection saldiri yukleri',
    'cp-core/tests/Unit/Core/Migrate/DatabaseSourceTest.php' => 'gecersiz onek testi icin saldiri yuku',
    'cp-core/tests/bootstrap.php' => 'yalnizca yorum metni',
    'cp-content/modules/Importer/' => 'yabanci CMS semalari (Joomla, MyBB, XenForo)',
];

$files = [];
foreach ($scanDirs as $dir) {
    $full = $root . '/' . $dir;
    if (!is_dir($full)) {
        continue;
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        // .sql covers the per-module Resources/migrations files, which create
        // tables of their own. cp-core/migrations is not in $scanDirs and must
        // stay that way: those files are the history of how the database got
        // here, and a migration describing a table name that did not exist when
        // it ran is worse than no migration at all.
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'yaml', 'yml', 'xml', 'sql'], true)) {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

/**
 * Context patterns. Each is a regex with the table name as a {T} placeholder;
 * the name is quoted into it so no table name can smuggle in regex syntax.
 */
$contexts = [
    'ORM\Table'      => '/(#\[ORM\\\\Table\(name:\s*[\'"])({T})([\'"])/',
    'ORM\JoinTable'  => '/(#\[ORM\\\\JoinTable\(name:\s*[\'"])({T})([\'"])/',
    'DBAL insert'    => '/(->insert\(\s*[\'"])({T})([\'"])/',
    'DBAL update'    => '/(->update\(\s*[\'"])({T})([\'"])/',
    'DBAL delete'    => '/(->delete\(\s*[\'"])({T})([\'"])/',
    'SQL FROM'       => '/(\bFROM\s+`?)({T})(`?\b)/',
    'SQL JOIN'       => '/(\bJOIN\s+`?)({T})(`?\b)/',
    'SQL INTO'       => '/(\bINTO\s+`?)({T})(`?\b)/',
    'SQL UPDATE'     => '/(\bUPDATE\s+`?)({T})(`?\b)/',
    'SQL TABLE'      => '/(\bTABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?)({T})(`?\b)/',
    // Foreign keys name the table they point at, and a module SQL file that
    // still points at `users` after the rename fails on install rather than at
    // any point a test would notice.
    'SQL REFERENCES' => '/(\bREFERENCES\s+`?)({T})(`?\b)/',
];

$changes = [];
$changedFiles = [];
$leftovers = [];

$skipped = [];

foreach ($files as $path) {
    $src = (string) file_get_contents($path);
    $original = $src;
    $rel = str_replace([$root . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);

    $skipReason = null;
    foreach ($excluded as $fragment => $reason) {
        if (str_contains($rel, $fragment)) {
            $skipReason = $reason;

            break;
        }
    }

    if ($skipReason !== null) {
        $skipped[$rel] = $skipReason;

        continue;
    }

    foreach ($renames as $old => $new) {
        foreach ($contexts as $label => $pattern) {
            $rx = str_replace('{T}', preg_quote($old, '/'), $pattern);
            $src = preg_replace_callback(
                $rx,
                static function (array $m) use (&$changes, $rel, $label, $old, $new): string {
                    $changes[] = [$rel, $label, $old, $new];

                    return $m[1] . $new . $m[3];
                },
                $src
            ) ?? $src;
        }
    }

    // Module installers declare their tables as bare literals in tables(). Inside
    // that method body a literal IS a table name by definition, so rewriting it is
    // safe in a way that rewriting a bare 'menus' anywhere else would not be.
    // Merged sources collapse to their single target, deduplicated.
    if (str_ends_with($rel, 'Install/ModuleInstaller.php')) {
        $src = preg_replace_callback(
            '/(protected function tables\(\): array\s*\{\s*return \[)(.*?)(\];)/s',
            static function (array $m) use (&$changes, $rel, $renames, $mergedSources): string {
                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/', $m[2], $found);

                // Modules that own no tables return []; reformatting that into
                // an empty multi-line array is churn in a diff that should only
                // show table names moving.
                if ($found[1] === []) {
                    return $m[0];
                }

                $out = [];
                foreach ($found[1] as $table) {
                    if (isset($mergedSources[$table])) {
                        $new = $mergedSources[$table];
                        $changes[] = [$rel, 'installer tables() [birlesme]', $table, $new];
                    } elseif (isset($renames[$table])) {
                        $new = $renames[$table];
                        $changes[] = [$rel, 'installer tables()', $table, $new];
                    } else {
                        $new = $table;
                    }

                    $out[$new] = true;
                }

                $body = "\n";
                foreach (array_keys($out) as $t) {
                    $body .= "            '{$t}',\n";
                }

                return $m[1] . $body . '        ' . $m[3];
            },
            $src
        ) ?? $src;
    }

    if ($src !== $original) {
        $changedFiles[$rel] = true;
        if ($write) {
            file_put_contents($path, $src);
        }
    }

    // Report every remaining literal mention of a table that should have moved,
    // so nothing disappears silently into "the script handled it".
    foreach (array_merge(array_keys($renames), array_keys($mergedSources)) as $old) {
        if (preg_match_all('/[\'"`]' . preg_quote($old, '/') . '[\'"`]/', $src, $mm) > 0) {
            $leftovers[$old][$rel] = count($mm[0]);
        }
    }
}

// ---- report ----------------------------------------------------------------

$byContext = [];
foreach ($changes as [$rel, $label, $old, $new]) {
    $byContext[$label][] = "{$rel}: {$old} -> {$new}";
}

echo $write ? "== UYGULANDI ==\n\n" : "== KURU CALISTIRMA (yazilmadi) ==\n\n";

foreach ($byContext as $label => $lines) {
    printf("### %s (%d)\n", $label, count($lines));
    foreach ($lines as $l) {
        echo "  {$l}\n";
    }
    echo "\n";
}

printf("TOPLAM: %d degisiklik, %d dosya\n", count($changes), count($changedFiles));

if ($skipped !== []) {
    printf("\n== BILEREK ATLANAN DOSYALAR (%d) ==\n", count($skipped));
    foreach ($skipped as $rel => $reason) {
        printf("  %-68s %s\n", $rel, $reason);
    }
}

if ($leftovers !== []) {
    echo "\n== BAGLAM DISINDA KALAN LITERALLER (elle bakilmali) ==\n";
    foreach ($leftovers as $old => $where) {
        $target = $mergedSources[$old] ?? ($renames[$old] ?? '?');
        printf("\n%s (-> %s):\n", $old, $target);
        foreach ($where as $rel => $n) {
            printf("  %-72s %d\n", $rel, $n);
        }
    }
} else {
    echo "\nBaglam disinda kalan literal yok.\n";
}
