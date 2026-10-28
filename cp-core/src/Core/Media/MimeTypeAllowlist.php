<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * Core MIME-to-extension allowlist and single source of truth for upload validation (SEC-01/SEC-02).
 * Extensions are derived from validated MIME, never from client filenames; modules cannot extend this map.
 *
 * @see AssetManager::upload()
 * @see cp-core/docs/security/uploads-hardening.md
 */
final class MimeTypeAllowlist
{
    /**
     * finfo MIME => canonical disk extension (lowercase, no dot). SVG/HTML/archives/octet-stream intentionally excluded.
     *
     * @var array<string, string>
     */
    private const MAP = [
        // Images
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff' => 'tiff',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/vnd.microsoft.icon' => 'ico',
        'image/x-icon' => 'ico',

        // Documents — PDF only (browser sandbox + uploads/.htaccess CSP).
        'application/pdf' => 'pdf',

        // Video
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv',
        'video/quicktime' => 'mov',
        'video/x-matroska' => 'mkv',

        // Audio — intentionally excluded until explicitly added to this map.
    ];

    /**
     * Types an administrator may switch on for the media library (never on by default, and never
     * used by any other upload path). Still fail-closed: the extension comes from the detected MIME,
     * and nothing that a browser or server would execute or render (SVG, HTML, XML, scripts,
     * executables) is in this list, so it cannot be chosen.
     *
     * @var array<string, array{0: string, 1: string}> finfo MIME => [extension, group]
     */
    private const OPTIONAL = [
        'text/plain' => ['txt', 'documents'],
        'text/csv' => ['csv', 'documents'],
        'application/rtf' => ['rtf', 'documents'],
        'text/rtf' => ['rtf', 'documents'],
        'application/msword' => ['doc', 'documents'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'documents'],
        'application/vnd.ms-excel' => ['xls', 'documents'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'documents'],
        'application/vnd.ms-powerpoint' => ['ppt', 'documents'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'documents'],
        'application/vnd.oasis.opendocument.text' => ['odt', 'documents'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods', 'documents'],
        'application/vnd.oasis.opendocument.presentation' => ['odp', 'documents'],

        'application/zip' => ['zip', 'archives'],
        'application/x-zip-compressed' => ['zip', 'archives'],
        'application/vnd.rar' => ['rar', 'archives'],
        'application/x-rar' => ['rar', 'archives'],
        'application/x-rar-compressed' => ['rar', 'archives'],
        'application/x-7z-compressed' => ['7z', 'archives'],
        'application/gzip' => ['gz', 'archives'],
        'application/x-gzip' => ['gz', 'archives'],
        'application/x-tar' => ['tar', 'archives'],
        'application/x-bzip2' => ['bz2', 'archives'],

        'audio/mpeg' => ['mp3', 'audio'],
        'audio/mp3' => ['mp3', 'audio'],
        'audio/wav' => ['wav', 'audio'],
        'audio/x-wav' => ['wav', 'audio'],
        'audio/vnd.wave' => ['wav', 'audio'],
        'audio/ogg' => ['oga', 'audio'],
        'application/ogg' => ['oga', 'audio'],
        'audio/mp4' => ['m4a', 'audio'],
        'audio/x-m4a' => ['m4a', 'audio'],
        'audio/aac' => ['aac', 'audio'],
        'audio/flac' => ['flac', 'audio'],
        'audio/x-flac' => ['flac', 'audio'],
    ];

    /** Group of each core type, for the settings screen. */
    private const CORE_GROUPS = ['image/' => 'images', 'application/pdf' => 'documents', 'video/' => 'video'];

    /** @var array<string, string>|null active MIME => extension; null = the core map */
    private ?array $active = null;

    /**
     * A copy that accepts exactly the given extensions out of the core map plus the optional catalog.
     * Unknown extensions are ignored, so a stale setting can never widen the list.
     *
     * @param list<string> $extensions
     */
    public function restrictedTo(array $extensions): self
    {
        $wanted = array_flip(array_map('strtolower', $extensions));
        $copy = clone $this;
        $copy->active = [];
        foreach (self::MAP + array_map(static fn (array $row): string => $row[0], self::OPTIONAL) as $mime => $extension) {
            if (isset($wanted[$extension])) {
                $copy->active[$mime] = $extension;
            }
        }

        return $copy;
    }

    /**
     * Every extension that can be switched on, by group: core types first, then the optional catalog.
     *
     * @return array<string, list<string>> group => extensions
     */
    public static function catalog(): array
    {
        $groups = [];
        foreach (self::MAP as $mime => $extension) {
            $group = 'documents';
            foreach (self::CORE_GROUPS as $prefix => $name) {
                if (str_starts_with($mime, $prefix)) {
                    $group = $name;
                }
            }
            $groups[$group][] = $extension;
        }
        foreach (self::OPTIONAL as [$extension, $group]) {
            $groups[$group][] = $extension;
        }

        return array_map(static fn (array $list): array => array_values(array_unique($list)), $groups);
    }

    /** @return list<string> the extensions the core map allows with no setting involved */
    public static function coreExtensions(): array
    {
        return array_values(array_unique(array_values(self::MAP)));
    }

    public function isAllowed(string $mimeType): bool
    {
        return isset($this->map()[$this->normalize($mimeType)]);
    }

    /** Return canonical extension for MIME, or null when not allowed (never invent a fallback). */
    public function extensionFor(string $mimeType): ?string
    {
        return $this->map()[$this->normalize($mimeType)] ?? null;
    }

    /**
     * All allowed MIME types (for accept attribute and error hints).
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return array_keys($this->map());
    }

    /**
     * Unique allowed extensions in alphabetical order for UI hints.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $extensions = array_values(array_unique(array_values($this->map())));
        sort($extensions);

        return $extensions;
    }

    /** @return array<string, string> */
    private function map(): array
    {
        return $this->active ?? self::MAP;
    }

    /** Normalize finfo MIME (strip parameters, lowercase) before lookup. */
    private function normalize(string $mimeType): string
    {
        $mimeType = trim($mimeType);

        $separatorPosition = strpos($mimeType, ';');
        if ($separatorPosition !== false) {
            $mimeType = substr($mimeType, 0, $separatorPosition);
        }

        return strtolower(trim($mimeType));
    }
}
