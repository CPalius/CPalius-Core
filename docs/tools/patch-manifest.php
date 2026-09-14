<?php

declare(strict_types=1);

/**
 * Builds a CPalius patch manifest from a list of changed files.
 *
 * A maintainer tool, deliberately living under docs/ rather than in
 * cp-core/src: docs/ is not in the release tarball (see SURUM_YAYINLAMA.md §3),
 * and the machinery for *publishing* patches has no business being installed on
 * every site that *receives* them.
 *
 * Why it exists at all: the manifest carries a SHA-256 per file, and the whole
 * security model rests on those digests being the digests of the files actually
 * pushed to the tag. Computing them by hand, once per file, at release time, is
 * the sort of task that is fine four times and wrong the fifth.
 *
 * Usage, from the project root:
 *
 *   php docs/tools/patch-manifest.php 1.1.1 1.1.0 "Kısa özet" \
 *       cp-core/src/Core/Version/CpVersion.php \
 *       cp-content/modules/Blog/Service/BlogCommentService.php
 *
 * Or let git decide what changed since the previous tag:
 *
 *   git diff --name-only v1.1.0..HEAD | php docs/tools/patch-manifest.php 1.1.1 1.1.0 "Kısa özet" -
 *
 * Prefix a path with "-" to record a deletion:
 *
 *   php docs/tools/patch-manifest.php 1.1.1 1.1.0 "..." -cp-content/modules/Blog/Old.php
 *
 * It prints JSON on stdout. Redirect it into the version repository:
 *
 *   ... > ../cpalius-version/patches/1.1.1.json
 */

const PATCHABLE_PREFIXES = ['cp-core/', 'cp-content/', 'public/'];
const PATCHABLE_ROOT_FILES = ['composer.json', 'composer.lock', 'symfony.lock', 'importmap.php', '.env.example', 'LICENSE'];
const PROTECTED_PREFIXES = ['public/uploads/', 'public/page-cache/', 'cp-core/var/'];
const VERSION_FILE = 'cp-core/src/Core/Version/CpVersion.php';
const MAX_FILE_BYTES = 8388608;

/**
 * Mirrors ProtectedPaths::normalizePatchPath. Duplicated on purpose: this
 * script has to run without booting the application, and a manifest that this
 * tool accepts but the installer rejects would only be discovered by the first
 * operator who clicked Apply.
 */
function normalize(string $raw): ?string
{
    $path = trim(str_replace('\\', '/', $raw));

    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || preg_match('#^[a-zA-Z]:#', $path) === 1) {
        return null;
    }

    foreach (PROTECTED_PREFIXES as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return null;
        }
    }

    $base = basename($path);

    if (($base === '.env' || str_starts_with($base, '.env.')) && $path !== '.env.example') {
        return null;
    }

    if (in_array($path, PATCHABLE_ROOT_FILES, true)) {
        return $path;
    }

    foreach (PATCHABLE_PREFIXES as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return $path;
        }
    }

    return null;
}

function fail(string $message): never
{
    fwrite(STDERR, 'ERROR: '.$message."\n");
    exit(1);
}

$argv = $_SERVER['argv'];

if (count($argv) < 5) {
    fwrite(STDERR, "usage: patch-manifest.php <version> <base> <summary> <path|-path|->...\n");
    exit(2);
}

$version = $argv[1];
$base = $argv[2];
$summary = $argv[3];
$inputs = array_slice($argv, 4);

foreach ([['version', $version], ['base', $base]] as [$label, $value]) {
    if (preg_match('/^\d+(\.\d+){1,3}$/', $value) !== 1) {
        fail(sprintf('%s "%s" is not comparable with version_compare().', $label, $value));
    }
}

if (version_compare($version, $base, '<=')) {
    fail(sprintf('%s does not supersede %s.', $version, $base));
}

// "-" on its own means "read the list from stdin", so this composes with
// git diff --name-only.
if (in_array('-', $inputs, true)) {
    $inputs = array_values(array_filter($inputs, static fn (string $i): bool => $i !== '-'));
    $piped = stream_get_contents(STDIN);

    foreach (preg_split('/\R/', (string) $piped) ?: [] as $line) {
        if (trim($line) !== '') {
            $inputs[] = trim($line);
        }
    }
}

$projectDir = rtrim(str_replace('\\', '/', getcwd() ?: '.'), '/');
$files = [];
$seen = [];
$sawVersionFile = false;

foreach ($inputs as $input) {
    $isDelete = str_starts_with($input, '-');
    $raw = $isDelete ? substr($input, 1) : $input;

    $path = normalize($raw);

    if ($path === null) {
        fail(sprintf('"%s" is not a path a patch may write. Vendor, uploads, var and .env are all off limits.', $raw));
    }

    if (isset($seen[$path])) {
        fail(sprintf('"%s" is listed more than once.', $path));
    }

    $seen[$path] = true;

    if ($path === VERSION_FILE) {
        $sawVersionFile = true;

        if ($isDelete) {
            fail('The version file cannot be a deletion.');
        }
    }

    if ($isDelete) {
        $files[] = ['path' => $path, 'action' => 'delete'];

        continue;
    }

    $absolute = $projectDir.'/'.$path;

    if (!is_file($absolute)) {
        fail(sprintf('"%s" does not exist in the working tree. Run this from the project root, on the commit you are about to tag.', $path));
    }

    $size = filesize($absolute);

    if ($size === false || $size > MAX_FILE_BYTES) {
        fail(sprintf('"%s" is too large for a patch.', $path));
    }

    $files[] = [
        'path' => $path,
        'action' => 'write',
        'sha256' => hash_file('sha256', $absolute),
        'size' => $size,
    ];
}

if ($files === []) {
    fail('No files.');
}

// The installer refuses a patch without it, because a patch that does not move
// CpVersion::VERSION would be offered again on every check forever.
if (!$sawVersionFile) {
    fail(sprintf('A patch must include %s, and its VERSION constant must already read %s.', VERSION_FILE, $version));
}

$declared = null;

if (preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", (string) file_get_contents($projectDir.'/'.VERSION_FILE), $m) === 1) {
    $declared = $m[1];
}

if ($declared !== $version) {
    fail(sprintf(
        'The working tree declares VERSION = %s but this patch claims %s. Bump the constant and re-run.',
        var_export($declared, true),
        $version,
    ));
}

echo json_encode([
    'schema' => 1,
    'version' => $version,
    'base' => $base,
    'released_at' => gmdate('Y-m-d'),
    'critical' => false,
    'summary' => $summary,
    'source' => sprintf('https://raw.githubusercontent.com/CPalius/CPalius-Core/v%s/', $version),
    'files' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
