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

    public function isAllowed(string $mimeType): bool
    {
        return isset(self::MAP[$this->normalize($mimeType)]);
    }

    /** Return canonical extension for MIME, or null when not allowed (never invent a fallback). */
    public function extensionFor(string $mimeType): ?string
    {
        return self::MAP[$this->normalize($mimeType)] ?? null;
    }

    /**
     * All allowed MIME types (for accept attribute and error hints).
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Unique allowed extensions in alphabetical order for UI hints.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $extensions = array_values(array_unique(array_values(self::MAP)));
        sort($extensions);

        return $extensions;
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
