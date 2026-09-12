<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

/**
 * Extracts the uploads-relative tail of a WordPress media URL.
 *
 * One implementation, used both to find the file on disk and to recognise the
 * same file inside post markup. Two copies of this rule would drift, and the
 * symptom would be images that import but do not get rewritten — the hardest
 * kind of half-working to notice.
 */
final class UrlPath
{
    private const MARKER = 'wp-content/uploads/';

    /**
     * "https://old.example/wp-content/uploads/2024/03/photo.jpg" => "2024/03/photo.jpg"
     */
    public function relative(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $path = str_contains($url, '://') || str_starts_with($url, '//')
            ? parse_url($url, \PHP_URL_PATH)
            : $url;

        if (!\is_string($path) || $path === '') {
            return null;
        }

        $path = rawurldecode($path);
        $position = strpos($path, self::MARKER);

        if ($position !== false) {
            $relative = substr($path, $position + \strlen(self::MARKER));
        } elseif (preg_match('#(\d{4}/\d{2}/[^/]+)$#', $path, $matches) === 1) {
            // Installs that moved the uploads folder still keep the YYYY/MM shape.
            $relative = $matches[1];
        } else {
            return null;
        }

        $relative = ltrim($relative, '/');

        return $relative === '' ? null : $relative;
    }
}
