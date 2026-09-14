<?php

declare(strict_types=1);

namespace App\Core\Version;

/**
 * One file-level patch, parsed and validated.
 *
 * A patch is the answer to a question a full release cannot answer cheaply: one
 * file changed upstream, and every installation in the field should get it
 * without downloading a forty-megabyte archive and rewriting nine thousand
 * files that did not change.
 *
 * That convenience is also the danger. A release archive is one artefact with
 * one digest that the operator can see is ours; a patch is a list of URLs and
 * a list of hashes, and the list itself is the thing being trusted. So every
 * check that a release gets by construction is written out by hand here:
 *
 *   - the manifest must come from a CPalius host (enforced by PatchChecker,
 *     using the same allowlist ReleaseChecker applies to release pointers)
 *   - every file must carry a SHA-256, verified after download and before the
 *     file is allowed anywhere near the project tree
 *   - every path must be one a patch is permitted to write (ProtectedPaths)
 *   - the patch must say which version it upgrades FROM, and that must be the
 *     version actually running — so patches apply in order or not at all
 *   - the patch must include CpVersion.php, so applying one moves the running
 *     version forward; a patch that left the version where it was would be
 *     offered again forever
 *
 * Construction is private: parse() is the only way in, and it either returns a
 * manifest that has passed all of the above or throws.
 */
final class PatchManifest
{
    /** A patch that rewrites more than this is a release, not a patch. */
    public const MAX_FILES = 400;

    /** No single source file in this project is anywhere near this large. */
    public const MAX_FILE_BYTES = 8388608;

    public const ACTION_WRITE = 'write';
    public const ACTION_DELETE = 'delete';

    /**
     * The file whose presence makes a patch self-describing. Named here rather
     * than inline so the installer's post-download assertion and this check
     * cannot drift apart.
     */
    public const VERSION_FILE = 'cp-core/src/Core/Version/CpVersion.php';

    /**
     * @param list<array{path: string, action: string, sha256: ?string, size: int}> $files
     */
    private function __construct(
        public readonly string $version,
        public readonly string $base,
        public readonly ?string $releasedAt,
        public readonly bool $critical,
        public readonly string $summary,
        public readonly string $source,
        public readonly array $files,
    ) {
    }

    /**
     * @param array<string, mixed> $raw     decoded manifest JSON
     * @param list<string>         $trusted allowed prefixes for the file source base
     *
     * @throws \RuntimeException with an operator-readable reason
     */
    public static function parse(array $raw, array $trusted): self
    {
        if ((int) ($raw['schema'] ?? 0) !== 1) {
            throw new \RuntimeException('Unsupported patch manifest schema.');
        }

        $version = self::versionString($raw['version'] ?? null, 'version');
        $base = self::versionString($raw['base'] ?? null, 'base');

        // Ordered with version_compare for the same reason UpdateHookInterface
        // is: a patch that claims to upgrade to something older than its own
        // base would be applied and then immediately offered again.
        if (version_compare($version, $base, '<=')) {
            throw new \RuntimeException(sprintf('Patch %s does not supersede its base %s.', $version, $base));
        }

        $source = trim((string) ($raw['source'] ?? ''));

        if ($source === '' || !str_ends_with($source, '/')) {
            throw new \RuntimeException('Patch source must be a base URL ending in "/".');
        }

        // The single most important line in this class: file contents are
        // fetched from here, so an unvetted host would be remote code
        // execution with extra steps. The digests below would still catch a
        // tampered body — but only because the manifest they came from was
        // itself fetched from a host on this list.
        $allowed = false;

        foreach ($trusted as $prefix) {
            if (str_starts_with($source, $prefix)) {
                $allowed = true;

                break;
            }
        }

        if (!$allowed) {
            throw new \RuntimeException(sprintf('Patch source "%s" is not a trusted CPalius location.', $source));
        }

        $rawFiles = $raw['files'] ?? null;

        if (!is_array($rawFiles) || $rawFiles === []) {
            throw new \RuntimeException('Patch manifest lists no files.');
        }

        if (count($rawFiles) > self::MAX_FILES) {
            throw new \RuntimeException(sprintf('Patch lists %d files; the limit is %d.', count($rawFiles), self::MAX_FILES));
        }

        $files = [];
        $seen = [];

        foreach ($rawFiles as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('Patch file entry is not an object.');
            }

            $path = ProtectedPaths::normalizePatchPath((string) ($entry['path'] ?? ''));

            if ($path === null) {
                throw new \RuntimeException(sprintf('Patch names a path it may not write: "%s".', (string) ($entry['path'] ?? '')));
            }

            // Two entries for one path would make the outcome depend on
            // iteration order, and one of the two orders deletes a file the
            // patch also wanted to write.
            if (isset($seen[$path])) {
                throw new \RuntimeException(sprintf('Patch lists "%s" more than once.', $path));
            }

            $seen[$path] = true;

            $action = (string) ($entry['action'] ?? self::ACTION_WRITE);

            if ($action === self::ACTION_DELETE) {
                $files[] = ['path' => $path, 'action' => self::ACTION_DELETE, 'sha256' => null, 'size' => 0];

                continue;
            }

            if ($action !== self::ACTION_WRITE) {
                throw new \RuntimeException(sprintf('Unknown patch action "%s".', $action));
            }

            $sha = strtolower(trim((string) ($entry['sha256'] ?? '')));

            // No digest, no install. There is no "trust the transport" path
            // here: an archive without a checksum is refused by CoreUpdater for
            // the same reason, and a patch is the easier of the two to forge.
            if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
                throw new \RuntimeException(sprintf('Patch entry "%s" has no usable SHA-256.', $path));
            }

            $size = (int) ($entry['size'] ?? 0);

            if ($size < 0 || $size > self::MAX_FILE_BYTES) {
                throw new \RuntimeException(sprintf('Patch entry "%s" declares an implausible size.', $path));
            }

            $files[] = ['path' => $path, 'action' => self::ACTION_WRITE, 'sha256' => $sha, 'size' => $size];
        }

        if (!isset($seen[self::VERSION_FILE])) {
            throw new \RuntimeException(sprintf('Patch does not ship %s, so it could not advance the running version.', self::VERSION_FILE));
        }

        return new self(
            $version,
            $base,
            is_string($raw['released_at'] ?? null) ? $raw['released_at'] : null,
            (bool) ($raw['critical'] ?? false),
            mb_substr(trim((string) ($raw['summary'] ?? '')), 0, 500),
            $source,
            $files,
        );
    }

    /** Download URL for one entry. */
    public function urlFor(string $path): string
    {
        return $this->source.$path;
    }

    /** True when this patch is the next step up from the given running version. */
    public function appliesTo(string $runningVersion): bool
    {
        return version_compare($runningVersion, $this->base, '==');
    }

    /**
     * @return list<array{path: string, action: string, sha256: ?string, size: int}>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->files, static fn (array $f): bool => $f['action'] === self::ACTION_WRITE));
    }

    /**
     * @return list<string>
     */
    public function deletions(): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['path'],
            array_filter($this->files, static fn (array $f): bool => $f['action'] === self::ACTION_DELETE),
        ));
    }

    public function totalBytes(): int
    {
        $total = 0;

        foreach ($this->files as $file) {
            $total += $file['size'];
        }

        return $total;
    }

    private static function versionString(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d+(\.\d+){1,3}$/', $value) !== 1) {
            throw new \RuntimeException(sprintf('Patch manifest has an unusable "%s" value.', $field));
        }

        return $value;
    }
}
