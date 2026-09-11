<?php

declare(strict_types=1);

namespace Modules\Pages;

/**
 * First-path segments that must not become a published page slug.
 */
final class PageReservedSlugs
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            'admin',
            'aacp',
            'api',
            'ara',
            'blog',
            'cron',
            'forum',
            'forums',
            'hesap',
            'login',
            'logout',
            'media',
            'roadmap',
            'search',
            'sitemap.xml',
            'themes',
            'uploads',
            'whitepaper',
            'assets',
            'cp-thumb',
        ];
    }

    public static function isReserved(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        return $slug !== '' && \in_array($slug, self::values(), true);
    }
}
