<?php

declare(strict_types=1);

/**
 * Checks table-map.php against a real database dump.
 *
 * The map is only trustworthy if every table in the database falls into exactly
 * one bucket. A table in no bucket would be silently left behind by the rename;
 * a table in two would get contradictory instructions.
 *
 * Usage: php tools/schema/verify-map.php <dump.sql>
 */

$map = require __DIR__ . '/table-map.php';

$dump = $argv[1] ?? null;
if ($dump === null || !is_file($dump)) {
    fwrite(STDERR, "Usage: php tools/schema/verify-map.php <dump.sql>\n");
    exit(2);
}

preg_match_all('/CREATE TABLE `([^`]+)`/', (string) file_get_contents($dump), $m);
$actual = $m[1];
sort($actual);

$buckets = [];
foreach ($map['renames'] as $old => $new) {
    $buckets[$old][] = "renames -> {$new}";
}
foreach ($map['merges'] as $target => $spec) {
    foreach ($spec['sources'] as $old => $value) {
        $buckets[$old][] = "merges -> {$target} ({$spec['discriminator']}={$value})";
    }
}
foreach ($map['unchanged'] as $t) {
    $buckets[$t][] = 'unchanged';
}
foreach ($map['framework'] as $t) {
    $buckets[$t][] = 'framework';
}

$problems = 0;

$missing = array_diff($actual, array_keys($buckets));
if ($missing !== []) {
    $problems += count($missing);
    echo "HICBIR KOVADA OLMAYAN TABLOLAR (" . count($missing) . "):\n";
    foreach ($missing as $t) {
        echo "  - {$t}\n";
    }
    echo "\n";
}

$ghost = array_diff(array_keys($buckets), $actual);
if ($ghost !== []) {
    $problems += count($ghost);
    echo "HARITADA VAR AMA VERITABANINDA YOK (" . count($ghost) . "):\n";
    foreach ($ghost as $t) {
        echo "  - {$t}\n";
    }
    echo "\n";
}

foreach ($buckets as $t => $where) {
    if (count($where) > 1) {
        ++$problems;
        echo "BIRDEN FAZLA KOVADA: {$t} -> " . implode(' | ', $where) . "\n";
    }
}

// New names must be unique and must all follow the convention.
$newNames = array_values($map['renames']);
$newNames = array_merge($newNames, array_keys($map['merges']), $map['unchanged']);
$dupes = array_filter(array_count_values($newNames), static fn (int $n): bool => $n > 1);
foreach ($dupes as $name => $n) {
    ++$problems;
    echo "CAKISAN YENI AD: {$name} ({$n} kez)\n";
}

foreach ($newNames as $name) {
    if (!str_starts_with($name, 'cp_')) {
        ++$problems;
        echo "KURALA UYMAYAN AD: {$name}\n";
    }
}

$before = count($actual);
$after = count($newNames) + count($map['framework']);

printf("\nONCE : %d tablo\n", $before);
printf("SONRA: %d tablo  (%d yeniden adlandirma, %d birlesme kaynagi -> %d hedef, %d degismeyen, %d framework)\n",
    $after,
    count($map['renames']),
    array_sum(array_map(static fn (array $s): int => count($s['sources']), $map['merges'])),
    count($map['merges']),
    count($map['unchanged']),
    count($map['framework']));

if ($problems === 0) {
    echo "\nSONUC: harita tutarli, her tablo tam olarak bir kovada.\n";
    exit(0);
}

printf("\nSONUC: %d sorun bulundu.\n", $problems);
exit(1);
