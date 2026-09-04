<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final class RoadmapSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
        private readonly ?RoadmapEntryRepository $entryRepository = null,
    ) {
    }

    public function priority(): int
    {
        return 60;
    }

    public function supports(Request $request): bool
    {
        if ($this->entryRepository === null) {
            return false;
        }

        $route = (string) $request->attributes->get('_route', '');

        return str_starts_with($route, 'roadmap_');
    }

    public function document(Request $request): ?SeoDocument
    {
        if ($this->entryRepository === null) {
            return null;
        }

        $route = (string) $request->attributes->get('_route', '');
        $locale = (string) $request->getLocale();

        if ($route === 'roadmap_show') {
            return $this->entry($request, $locale);
        }

        if ($route === 'roadmap_index') {
            return new SeoDocument(
                headline: 'Roadmap',
                description: (string) $this->settings->getForLocale('seo.default_description', $locale, ''),
                canonicalPath: $this->urls->absolute('roadmap_index', ['_locale' => $locale], $locale),
                ogType: 'website',
                contentKind: 'roadmap',
                schemaType: 'ItemList',
                breadcrumbs: [
                    ['name' => 'Home', 'url' => $this->urls->absolute('theme_cpalius_website_home', ['_locale' => $locale], $locale)],
                ],
                locale: $locale,
            );
        }

        return null;
    }

    private function entry(Request $request, string $locale): ?SeoDocument
    {
        $slug = (string) $request->attributes->get('slug', '');
        $entry = $this->entryRepository?->findOneBySlugAndLocale($slug, $locale);
        if (!$entry instanceof RoadmapEntry) {
            return null;
        }

        $url = $this->urls->absolute('roadmap_show', ['_locale' => $locale, 'slug' => $entry->getSlug()], $locale);

        return new SeoDocument(
            headline: $entry->getTitle(),
            description: $entry->getSummary(),
            canonicalPath: $url,
            ogType: 'article',
            contentKind: 'roadmap',
            schemaType: (string) $this->settings->get('seo.roadmap.entry_schema', 'TechArticle'),
            breadcrumbs: [
                ['name' => 'Roadmap', 'url' => $this->urls->absolute('roadmap_index', ['_locale' => $locale], $locale)],
            ],
            publishedAt: $entry->getPublishedAt(),
            modifiedAt: $entry->getUpdatedAt(),
            locale: $locale,
            schemaExtra: [
                'version' => $entry->getVersionLabel(),
            ],
        );
    }
}
