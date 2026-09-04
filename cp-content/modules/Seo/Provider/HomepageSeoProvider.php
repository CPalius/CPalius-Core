<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final class HomepageSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
    ) {
    }

    public function priority(): int
    {
        return 40;
    }

    public function supports(Request $request): bool
    {
        return \in_array((string) $request->attributes->get('_route'), ['theme_cpalius_website_home', 'theme_home_root'], true);
    }

    public function document(Request $request): ?SeoDocument
    {
        $locale = (string) $request->getLocale();
        $title = trim((string) $this->settings->getForLocale('seo.default_title', $locale, ''));
        if ($title === '') {
            $title = (string) $this->settings->getForLocale('core.site_name', $locale, 'CPalius CMF');
        }
        $description = trim((string) $this->settings->getForLocale('seo.default_description', $locale, ''));
        if ($description === '') {
            $description = (string) $this->settings->getForLocale('core.default_meta_description', $locale, '');
        }

        return new SeoDocument(
            headline: $title,
            description: $description,
            canonicalPath: $this->urls->absolute('theme_cpalius_website_home', ['_locale' => $locale], $locale),
            ogType: 'website',
            contentKind: 'page',
            schemaType: 'WebPage',
            breadcrumbs: [],
            locale: $locale,
        );
    }
}
