<?php

declare(strict_types=1);

namespace Modules\Seo\Install;

use Doctrine\DBAL\Connection;

/**
 * Inserts CPalius identity copy when a key is missing or an empty locale map.
 * Never overwrites a value the site owner already saved.
 */
final class SeoSettingsSeeder
{
    public static function seed(Connection $connection): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $written = 0;

        foreach (self::rows() as $key => $value) {
            try {
                $existing = $connection->fetchOne(
                    'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                    ['key' => $key],
                );
            } catch (\Throwable) {
                continue;
            }

            if ($existing === false || $existing === null) {
                try {
                    $connection->executeStatement(
                        'INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (:key, :value, :module, :now)',
                        ['key' => $key, 'value' => $value, 'module' => 'seo', 'now' => $now],
                    );
                    ++$written;
                } catch (\Throwable) {
                    // Unique race or missing table: skip.
                }
                continue;
            }

            if (!\is_string($existing) || !self::isBlank($existing)) {
                continue;
            }

            try {
                $connection->executeStatement(
                    'UPDATE cp_settings SET setting_value = :value, updated_at = :now WHERE setting_key = :key',
                    ['key' => $key, 'value' => $value, 'now' => $now],
                );
                ++$written;
            } catch (\Throwable) {
                // Stale row stays until the next seed.
            }
        }

        return $written;
    }

    /**
     * @return array<string, string>
     */
    public static function rows(): array
    {
        $titleTpl = self::map('%title% – %site_name%');

        return [
            'seo.default_title' => self::map('CPalius CMF'),
            'seo.default_description' => self::map(
                'CPalius CMF: kurumsal seviyede güvenli, modüler ve genişletilebilir içerik yönetim framework\'ü.',
                'CPalius CMF: an enterprise-grade secure, modular, and extensible content management framework.',
            ),
            'seo.og_title' => self::map(
                'CPalius CMF — Yeni Nesil İçerik Yönetim Framework\'ü',
                'CPalius CMF — Next-Generation Content Management Framework',
            ),
            'seo.og_description' => self::map(
                'CPalius CMF: PHP 8.2+ ve Symfony 7.4 LTS üzerine inşa edilmiş, kurumsal seviyede güvenli, modüler ve genişletilebilir içerik yönetim framework\'ü.',
                'CPalius CMF: a secure, modular, and extensible enterprise content management framework built on PHP 8.2+ and Symfony 7.4 LTS.',
            ),
            'seo.organization_name' => self::map('CPalius'),
            'seo.organization_description' => self::map(
                'CPalius CMF, PHP 8.2+ ve Symfony 7.4 LTS üzerine inşa edilmiş yeni nesil içerik yönetim framework\'üdür. Geliştiricilere dünya standartlarında, optimize, güvenli ve genişletilebilir bir altyapı sunar.',
                'CPalius CMF is a next-generation content management framework built on PHP 8.2+ and Symfony 7.4 LTS. It gives developers a world-class, optimized, secure, and extensible foundation.',
            ),
            'seo.page.title_template' => $titleTpl,
            'seo.blog.title_template' => $titleTpl,
            'seo.forum.title_template' => $titleTpl,
            'seo.roadmap.title_template' => $titleTpl,
            'seo.default_og_image' => '/CPalius.png',
            'seo.twitter_card' => 'summary_large_image',
            'seo.site_indexable' => '1',
            'seo.index_blog_listings' => '1',
            'seo.index_search' => '0',
            'seo.index_pagination' => '0',
            'seo.index_forum_profiles' => '0',
            'seo.index_forum_activity' => '0',
            'seo.blog.article_schema' => 'BlogPosting',
            'seo.blog.project_schema' => 'SoftwareSourceCode',
            'seo.forum.topic_schema' => 'DiscussionForumPosting',
            'seo.roadmap.entry_schema' => 'TechArticle',
            'seo.include_search_action' => '1',
            'seo.sitemap_enabled' => '1',
            'seo.sitemap_include_forum' => '1',
            'seo.sitemap_include_images' => '1',
            'seo.sitemap_include_videos' => '1',
        ];
    }

    private static function map(string $tr, ?string $en = null): string
    {
        return json_encode(['tr' => $tr, 'en' => $en ?? $tr], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private static function isBlank(string $raw): bool
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed === '{}' || $trimmed === '[]' || $trimmed === 'null') {
            return true;
        }
        if (!str_starts_with($trimmed, '{')) {
            return false;
        }

        try {
            $decoded = json_decode($trimmed, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        if (!\is_array($decoded) || $decoded === []) {
            return true;
        }

        foreach ($decoded as $value) {
            if (\is_string($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
