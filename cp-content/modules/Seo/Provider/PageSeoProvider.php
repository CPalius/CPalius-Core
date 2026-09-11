<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Asset;
use App\Entity\Node;
use App\Repository\AssetRepository;
use App\Repository\NodeRepository;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final class PageSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly AssetRepository $assets,
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
    ) {
    }

    public function priority(): int
    {
        return 70;
    }

    public function supports(Request $request): bool
    {
        return (string) $request->attributes->get('_route', '') === 'page_show';
    }

    public function document(Request $request): ?SeoDocument
    {
        $locale = (string) $request->getLocale();
        $slug = (string) $request->attributes->get('slug', '');
        $node = $this->nodes->findOnePublishedBySlugAndLocale($slug, $locale);
        if (!$node instanceof Node || $node->getType() !== 'page') {
            return null;
        }

        $seo = $node->getDataValue('seo', []);
        $seo = \is_array($seo) ? $seo : [];

        $description = trim((string) ($seo['meta_description'] ?? ''));
        if ($description === '') {
            $description = trim(strip_tags((string) $node->getDataValue('excerpt', '')));
        }
        if ($description === '') {
            $description = (string) $this->settings->getForLocale('pages.meta_description_fallback', $locale, '');
        }

        $canonical = !empty($seo['canonical_url'])
            ? (string) $seo['canonical_url']
            : $this->urls->absolute('page_show', ['_locale' => $locale, 'slug' => $node->getSlug()], $locale);

        $noindex = !empty($seo['noindex']);
        $images = $this->images($node);

        return new SeoDocument(
            headline: $node->getTitle(),
            description: $description,
            canonicalPath: $canonical,
            ogType: 'website',
            contentKind: 'page',
            schemaType: 'WebPage',
            robots: $noindex ? 'noindex, follow' : 'index, follow',
            breadcrumbs: [
                ['name' => $node->getTitle(), 'url' => $canonical],
            ],
            images: $images,
            publishedAt: $node->getPublishedAt(),
            modifiedAt: $node->getUpdatedAt(),
            locale: $locale,
            forceNoindex: $noindex,
        );
    }

    /**
     * @return list<string>
     */
    private function images(Node $node): array
    {
        $raw = $node->getDataValue('featured_image_asset_id');
        if (!is_numeric($raw)) {
            return [];
        }

        $asset = $this->assets->find((int) $raw);
        if (!$asset instanceof Asset || $asset->getStorageKey() === null) {
            return [];
        }

        return [$this->urls->assetUrl($asset->getStorageKey(), $node->getLocale())];
    }
}
