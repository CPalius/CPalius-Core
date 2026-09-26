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
            if ($document->authorUrl !== null && $document->authorUrl !== '') {
                $entity['author']['url'] = $document->authorUrl;
            }
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
            $video = $this->video($document, $title);
            if ($video !== null) {
                $entity['video'] = $video;
            }
        }

        foreach ($document->schemaExtra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $entity[$key] = $value;
        }

        return $entity;
    }

    /**
     * Google requires name, thumbnailUrl and uploadDate on every VideoObject; an
     * item missing one is invalid, so no thumbnail means no video node at all.
     * YouTube/Vimeo links are player pages (embedUrl), only a file is a contentUrl.
     *
     * @return array<string, mixed>|null
     */
    private function video(SeoDocument $document, string $title): ?array
    {
        $url = (string) $document->videoUrl;
        $thumbnail = $document->images[0] ?? null;
        $node = ['@type' => 'VideoObject', 'name' => $document->videoTitle ?: $title];

        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})~i', $url, $m) === 1) {
            $node['embedUrl'] = 'https://www.youtube.com/embed/'.$m[1];
            $thumbnail ??= 'https://i.ytimg.com/vi/'.$m[1].'/hqdefault.jpg';
        } elseif (preg_match('~vimeo\.com/(?:video/)?(\d+)~i', $url, $m) === 1) {
            $node['embedUrl'] = 'https://player.vimeo.com/video/'.$m[1];
        } else {
            $node['contentUrl'] = $url;
        }

        $uploaded = $document->publishedAt ?? $document->modifiedAt;
        if ($thumbnail === null || !$uploaded instanceof \DateTimeInterface) {
            return null;
        }
        $node['thumbnailUrl'] = $thumbnail;
        $node['uploadDate'] = $uploaded->format(\DATE_ATOM);
        if ($document->description !== '') {
            $node['description'] = $document->description;
        }

        return $node;
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
