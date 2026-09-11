<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Symfony\Component\HttpFoundation\Request;

final class PrivateAreaSeoProvider implements SeoPageProviderInterface
{
    private const NOINDEX_PREFIXES = [
        'admin_',
        'aacp_',
        'account_',
        'login',
        'register',
        'forum_notifications',
        'forum_new_topic',
        'forum_edit_post',
        'forum_reply',
        'forum_like',
        'forum_dislike',
        'forum_report',
        'forum_moderate',
        'forum_give_reputation',
        'forum_delete',
    ];

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');
        if ($this->isSearch($route) && !$this->isOn('seo.index_search')) {
            return true;
        }
        if ($route === 'forum_profile' && !$this->isOn('seo.index_forum_profiles')) {
            return true;
        }
        if ($route === 'forum_activity' && !$this->isOn('seo.index_forum_activity')) {
            return true;
        }

        foreach (self::NOINDEX_PREFIXES as $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix)) {
                return true;
            }
        }

        $path = $request->getPathInfo();

        return str_starts_with($path, '/admin') || str_starts_with($path, '/aacp');
    }

    public function document(Request $request): ?SeoDocument
    {
        $locale = (string) $request->getLocale();

        return new SeoDocument(
            headline: '',
            description: '',
            canonicalPath: $request->getPathInfo(),
            robots: 'noindex, nofollow',
            locale: $locale,
            forceNoindex: true,
        );
    }

    private function isSearch(string $route): bool
    {
        return \in_array($route, ['site_search', 'blog_search', 'forum_search'], true);
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '0');

        return $value === true || $value === 1 || $value === '1';
    }
}
