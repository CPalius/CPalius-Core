<?php

declare(strict_types=1);

/**
 * Checks the entity mappings against table-map.php.
 *
 * verify-map.php proves the map covers the old database; this proves the code
 * actually arrived at the names the map promises. Run both and the database,
 * the map and the entities are known to agree.
 *
 * The three tables driven by raw DBAL rather than an entity (cp_user_sessions,
 * cp_banned_ips, cp_password_history) and Doctrine's own migration table never
 * appear in a mapping dump, so they are declared here instead of silently
 * counting as missing.
 *
 * Usage:
 *   php cp-core/bin/console doctrine:schema:create --dump-sql > dump.txt
 *   php tools/schema/verify-entities.php dump.txt
 */

$map = require __DIR__ . '/table-map.php';

$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage: php tools/schema/verify-entities.php <schema-dump.txt>\n");
    exit(2);
}

/** Tables that exist without a Doctrine entity behind them. */
$withoutEntity = [
    'cp_user_sessions' => 'SessionRegistry, ham DBAL',
    'cp_banned_ips' => 'IpBanService, ham DBAL',
    'cp_password_history' => 'PasswordHistory, ham DBAL',
    'doctrine_migration_versions' => 'Doctrine Migrations kendi yonetir',
];

preg_match_all('/CREATE TABLE ([a-z_]+)/', (string) file_get_contents($file), $m);
$fromEntities = array_unique($m[1]);
sort($fromEntities);

$expected = array_merge(
    array_values($map['renames']),
    array_keys($map['merges']),
    $map['unchanged'],
    $map['framework'],
);
sort($expected);

$actual = array_merge($fromEntities, array_keys($withoutEntity));
$actual = array_values(array_unique($actual));
sort($actual);

$missing = array_diff($expected, $actual);
$extra = array_diff($actual, $expected);

$problems = 0;

if ($missing !== []) {
    $problems += count($missing);
    echo "HARITADA VAR, KODDA YOK (" . count($missing) . "):\n";
    foreach ($missing as $t) {
        echo "  - {$t}\n";
    }
    echo "\n";
}

if ($extra !== []) {
    $problems += count($extra);
    echo "KODDA VAR, HARITADA YOK (" . count($extra) . "):\n";
    foreach ($extra as $t) {
        echo "  - {$t}\n";
    }
    echo "\n";
}

$badName = array_filter(
    $fromEntities,
    static fn (string $t): bool => !str_starts_with($t, 'cp_') && !in_array($t, $map['framework'], true)
);
foreach ($badName as $t) {
    ++$problems;
    echo "KURALA UYMAYAN TABLO ADI: {$t}\n";
}

printf("\nEntity'den gelen: %d, entity'siz: %d, toplam: %d (beklenen %d)\n",
    count($fromEntities), count($withoutEntity), count($actual), count($expected));

if ($problems === 0) {
    echo "\nSONUC: entity'ler harita ile birebir uyusuyor.\n";
    exit(0);
}

printf("\nSONUC: %d sorun.\n", $problems);
exit(1);
