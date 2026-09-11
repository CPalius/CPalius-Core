<?php

declare(strict_types=1);

namespace Modules\Seo\Engine;

use App\Core\Settings\SettingsRegistry;
use Modules\Seo\Document\SeoDocument;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds a schema.org @graph: Organization, WebSite, breadcrumbs, and the page entity.
 */
final class SchemaGraphBuilder
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(SeoDocument $document, string $locale, string $canonical, string $title): array
    {
        $siteName = $this->orgName($locale);
        $home = $this->urls->absolute('theme_cpalius_website_home', ['_locale' => $locale], $locale);
        $orgId = $home.'#organization';
        $siteId = $home.'#website';

        $graph = [
            $this->organization($locale, $orgId, $home, $siteName),
            $this->website($locale, $siteId, $orgId, $home, $siteName),
        ];

        if ($document->breadcrumbs !== []) {
            $graph[] = $this->breadcrumbs($document->breadcrumbs, $canonical);
        }

        $graph[] = $this->pageEntity($document, $locale, $canonical, $title, $orgId, $siteId);

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(string $locale, string $id, string $home, string $name): array
    {
        $node = [
            '@type' => 'Organization',
            '@id' => $id,
            'name' => $name,
            'url' => $home,
        ];
        $desc = trim((string) $this->settings->getForLocale('seo.organization_description', $locale, ''));
        if ($desc !== '') {
            $node['description'] = $desc;
        }
        $logo = trim((string) $this->settings->get('seo.default_og_image', ''));
        if ($logo !== '') {
            $node['logo'] = $this->urls->absolutePath($logo, $locale);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function website(string $locale, string $id, string $orgId, string $home, string $name): array
    {
        $node = [
            '@type' => 'WebSite',
            '@id' => $id,
            'url' => $home,
            'name' => $name,
            'inLanguage' => $locale,
            'publisher' => ['@id' => $orgId],
        ];

        $searchTemplate = $this->searchUrlTemplate($locale);
        if ($searchTemplate !== null) {
            $node['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchTemplate,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $node;
    }

    /**
     * @param list<array{name: string, url: string}> $crumbs
     *
     * @return array<string, mixed>
     */
    private function breadcrumbs(array $crumbs, string $canonical): array
    {
        $items = [];
        $position = 1;
        foreach ($crumbs as $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
            ++$position;
        }

        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $canonical,
            'item' => $canonical,
        ];

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageEntity(SeoDocument $document, string $locale, string $canonical, string $title, string $orgId, string $siteId): array
    {
        $entity = [
            '@type' => $document->schemaType,
            '@id' => $canonical.'#content',
            'url' => $canonical,
            'name' => $title,
            'headline' => $document->headline !== '' ? $document->headline : $title,
            'inLanguage' => $locale,
            'isPartOf' => ['@id' => $siteId],
            'publisher' => ['@id' => $orgId],
        ];

        if ($document->description !== '') {
            $entity['description'] = $document->description;
        }
        if ($document->publishedAt instanceof \DateTimeInterface) {
            $entity['datePublished'] = $document->publishedAt->format(\DATE_ATOM);
        }
        if ($document->modifiedAt instanceof \DateTimeInterface) {
            $entity['dateModified'] = $document->modifiedAt->format(\DATE_ATOM);
        }
        if ($document->authorName !== null && $document->authorName !== '') {
            $entity['author'] = ['@type' => 'Person', 'name' => $document->authorName];
        }

        $images = [];
        foreach ($document->images as $image) {
            $images[] = [
                '@type' => 'ImageObject',
                'url' => $image,
            ];
        }
        if ($images !== []) {
            $entity['image'] = \count($images) === 1 ? $images[0] : $images;
        }

        if ($document->videoUrl !== null && $document->videoUrl !== '') {
            $entity['video'] = [
                '@type' => 'VideoObject',
                'name' => $document->videoTitle ?: $title,
                'contentUrl' => $document->videoUrl,
                'embedUrl' => $document->videoUrl,
            ];
        }

        foreach ($document->schemaExtra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $entity[$key] = $value;
        }

        return $entity;
    }

    private function orgName(string $locale): string
    {
        $name = trim((string) $this->settings->getForLocale('seo.organization_name', $locale, ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) $this->settings->getForLocale('core.site_name', $locale, 'CPalius CMF'));
    }

    private function searchUrlTemplate(string $locale): ?string
    {
        if (!$this->isOn('seo.include_search_action')) {
            return null;
        }

        foreach (['site_search', 'blog_search', 'forum_search'] as $route) {
            try {
                return $this->urls->absolute($route, ['_locale' => $locale], $locale).'?q={search_term_string}';
            } catch (RouteNotFoundException) {
                continue;
            }
        }

        return null;
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
