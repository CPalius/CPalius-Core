<?php

declare(strict_types=1);

namespace App\Core\Font;

/**
 * The installed typefaces, which are just directories under public/fonts/.
 *
 * There is no table behind this on purpose. A font family is a folder of files
 * plus a few lines of metadata, and putting that in the database would mean an
 * installation whose files and rows can disagree — restore one without the
 * other and you have a library listing fonts that 404, or files nobody can see.
 * Reading the directory makes the disk the single source of truth, and a family
 * copied in over FTP shows up on the screen without anything being told.
 *
 * Each family carries a font.json manifest written next to its files, so the
 * weights and styles survive the trip. A folder without one is still listed —
 * the files are what matter — with whatever can be inferred from the names.
 */
final class FontLibrary
{
    public const MANIFEST = 'font.json';
    public const PUBLIC_PREFIX = '/fonts';

    /** Extensions a browser can actually use, newest first. */
    public const ALLOWED_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'];

    private readonly string $root;

    public function __construct(string $projectDir)
    {
        $this->root = $projectDir.'/public/fonts';
    }

    public function rootPath(): string
    {
        return $this->root;
    }

    public function ensureRoot(): void
    {
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0o775, true);
        }
    }

    /**
     * @return list<FontFamily>
     */
    public function families(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $families = [];

        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $this->root.'/'.$entry;
            if (!is_dir($dir)) {
                continue;
            }

            $family = $this->read($entry);
            if ($family !== null) {
                $families[] = $family;
            }
        }

        usort($families, static fn (FontFamily $a, FontFamily $b): int => strcasecmp($a->name, $b->name));

        return $families;
    }

    public function read(string $slug): ?FontFamily
    {
        $slug = $this->sanitizeSlug($slug);
        $dir = $this->root.'/'.$slug;

        if ($slug === '' || !is_dir($dir)) {
            return null;
        }

        $manifest = $this->readManifest($dir);
        $faces = [];
        $bytes = 0;

        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir.'/'.$entry;
            if (!is_file($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, \PATHINFO_EXTENSION));
            if (!\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $bytes += (int) filesize($path);
            $meta = $manifest['files'][$entry] ?? null;

            $faces[] = new FontFace(
                file: $entry,
                url: self::PUBLIC_PREFIX.'/'.$slug.'/'.rawurlencode($entry),
                format: $extension === 'ttf' ? 'truetype' : ($extension === 'otf' ? 'opentype' : $extension),
                weight: \is_array($meta) ? (string) ($meta['weight'] ?? '400') : $this->guessWeight($entry),
                style: \is_array($meta) ? (string) ($meta['style'] ?? 'normal') : $this->guessStyle($entry),
                unicodeRange: \is_array($meta) && \is_string($meta['unicodeRange'] ?? null) ? $meta['unicodeRange'] : null,
            );
        }

        if ($faces === []) {
            return null;
        }

        // Lightest first, so the generated @font-face blocks read in order.
        usort($faces, static fn (FontFace $a, FontFace $b): int => [$a->weight, $a->style] <=> [$b->weight, $b->style]);

        return new FontFamily(
            slug: $slug,
            name: \is_string($manifest['family'] ?? null) && $manifest['family'] !== '' ? $manifest['family'] : $this->nameFromSlug($slug),
            faces: $faces,
            source: \is_string($manifest['source'] ?? null) ? $manifest['source'] : null,
            installedAt: \is_string($manifest['installed_at'] ?? null) ? $manifest['installed_at'] : null,
            bytes: $bytes,
        );
    }

    public function exists(string $slug): bool
    {
        return $this->read($slug) !== null;
    }

    /**
     * Removes a family and everything in its folder.
     *
     * Only files with a known font extension plus the manifest are unlinked, and
     * the folder is removed only once it is empty — so a directory that somehow
     * holds something else is left behind rather than deleted blind.
     */
    public function delete(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        $dir = $this->root.'/'.$slug;

        if ($slug === '' || !is_dir($dir)) {
            return false;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            $extension = strtolower(pathinfo($entry, \PATHINFO_EXTENSION));

            if (is_file($path) && (\in_array($extension, self::ALLOWED_EXTENSIONS, true) || $entry === self::MANIFEST)) {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Turns a family name into a directory name. Kept strict — this value is
     * concatenated into a filesystem path and into a public URL.
     */
    public function sanitizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * @return array{family?: string, source?: string, installed_at?: string, files?: array<string, array<string, mixed>>}
     */
    private function readManifest(string $dir): array
    {
        $path = $dir.'/'.self::MANIFEST;
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A corrupt manifest must not hide the files it describes.
            return [];
        }

        return \is_array($data) ? $data : [];
    }

    private function nameFromSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function guessWeight(string $file): string
    {
        $name = strtolower($file);

        foreach ([
            'thin' => '100', 'extralight' => '200', 'ultralight' => '200', 'light' => '300',
            'regular' => '400', 'normal' => '400', 'medium' => '500',
            'semibold' => '600', 'demibold' => '600', 'extrabold' => '800', 'ultrabold' => '800',
            'bold' => '700', 'black' => '900', 'heavy' => '900',
        ] as $needle => $weight) {
            if (str_contains($name, $needle)) {
                return $weight;
            }
        }

        // "Inter-600.woff2" and friends.
        if (preg_match('/[^0-9]([1-9]00)[^0-9]/', '-'.$name.'-', $m) === 1) {
            return $m[1];
        }

        return '400';
    }

    private function guessStyle(string $file): string
    {
        return str_contains(strtolower($file), 'italic') ? 'italic' : 'normal';
    }
}
