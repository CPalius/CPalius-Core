<?php

declare(strict_types=1);

namespace Modules\Seo\Engine;

use App\Core\Localization\Twig\LocaleRuntime;
use App\Core\Seo\SeoHeadRendererInterface;
use App\Core\Settings\SettingsRegistry;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Resolves the winning page provider and renders the full public <head> SEO payload.
 */
final class SeoEngine implements SeoHeadRendererInterface
{
    /**
     * @param iterable<SeoPageProviderInterface> $providers
     */
    public function __construct(
        #[TaggedIterator('cpalius.seo.page_provider')]
        private readonly iterable $providers,
        private readonly RequestStack $requestStack,
        private readonly SettingsRegistry $settings,
        private readonly TitleFormatter $titles,
        private readonly SeoUrlBuilder $urls,
        private readonly SchemaGraphBuilder $schema,
        private readonly LocaleRuntime $locales,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array{title?: string, description?: string, robots?: string} $overrides
     */
    public function render(array $overrides = []): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return '';
        }

        $document = $this->resolve($request) ?? new SeoDocument(
            headline: '',
            description: (string) $this->settings->get('seo.default_description', $this->settings->get('core.default_meta_description', '')),
            canonicalPath: $request->getPathInfo(),
        );

        $locale = $document->locale ?: (string) $request->getLocale();
        $templateKey = $this->templateKey($document->contentKind);
        $titleOverride = trim((string) ($overrides['title'] ?? ''));
        $descriptionOverride = trim((string) ($overrides['description'] ?? ''));
        $robotsOverride = trim((string) ($overrides['robots'] ?? ''));

        $headline = $document->headline !== '' ? $document->headline : $titleOverride;
        $title = $titleOverride !== '' ? $titleOverride : $this->titles->format($headline, $templateKey, $locale);
        $description = $descriptionOverride !== ''
            ? $descriptionOverride
            : $this->firstNonEmpty([
                $document->description,
                (string) $this->settings->getForLocale('seo.default_description', $locale, ''),
                (string) $this->settings->getForLocale('core.default_meta_description', $locale, ''),
            ]);

        $canonical = $document->canonicalPath !== ''
            ? $this->urls->absolutePath($document->canonicalPath, $locale)
            : $this->urls->currentCanonical($locale);

        $robots = $this->robots($document, $request, $robotsOverride);
        $ogImage = $this->ogImage($document, $locale);
        $ogTitle = trim((string) $this->settings->getForLocale('seo.og_title', $locale, '')) ?: $title;
        $ogDescription = trim((string) $this->settings->getForLocale('seo.og_description', $locale, '')) ?: $description;

        $siteName = trim((string) $this->settings->getForLocale('core.site_name', $locale, 'CPalius CMF'));
        $schemaJson = '{}';
        try {
            $schemaJson = (string) json_encode(
                $this->schema->build($document, $locale, $canonical, $title),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            );
        } catch (\Throwable) {
            $schemaJson = '{}';
        }

        return $this->twig->render('@SeoModule/head.html.twig', [
            'title' => $title,
            'description' => $description,
            'robots' => $robots,
            'canonical' => $canonical,
            'ogType' => $document->ogType,
            'ogTitle' => $ogTitle,
            'ogDescription' => $ogDescription,
            'ogImage' => $ogImage,
            'ogLocale' => $this->ogLocale($locale),
            'siteName' => $siteName,
            'twitterCard' => (string) $this->settings->get('seo.twitter_card', 'summary_large_image'),
            'twitterSite' => (string) $this->settings->get('seo.twitter_site', ''),
            'facebookAppId' => (string) $this->settings->get('seo.facebook_app_id', ''),
            'googleVerification' => (string) $this->settings->get('seo.google_site_verification', ''),
            'bingVerification' => (string) $this->settings->get('seo.bing_site_verification', ''),
            'yandexVerification' => (string) $this->settings->get('seo.yandex_site_verification', ''),
            'schemaJson' => $schemaJson,
            'hreflang' => $this->locales->hreflangLinks(),
            'prevUrl' => $document->prevUrl,
            'nextUrl' => $document->nextUrl,
            'locale' => $locale,
            'keywords' => trim($document->keywords),
        ]);
    }

    private function resolve(Request $request): ?SeoDocument
    {
        $ranked = [];
        foreach ($this->providers as $provider) {
            $ranked[] = $provider;
        }
        usort($ranked, static fn (SeoPageProviderInterface $a, SeoPageProviderInterface $b): int => $b->priority() <=> $a->priority());

        foreach ($ranked as $provider) {
            if (!$provider->supports($request)) {
                continue;
            }

            $document = $provider->document($request);
            if ($document instanceof SeoDocument) {
                return $document;
            }
        }

        return null;
    }

    private function templateKey(string $kind): string
    {
        return match ($kind) {
            'blog', 'article', 'project', 'software', 'image', 'video' => 'seo.blog.title_template',
            'forum' => 'seo.forum.title_template',
            'roadmap' => 'seo.roadmap.title_template',
            default => 'seo.page.title_template',
        };
    }

    private function robots(SeoDocument $document, Request $request, string $override): string
    {
        if (!$this->isOn('seo.site_indexable') || $document->forceNoindex) {
            return 'noindex, nofollow';
        }

        $page = $request->query->getInt('page', 1);
        if ($page > 1 && !$this->isOn('seo.index_pagination')) {
            return 'noindex, follow';
        }

        if ($override !== '') {
            return $override;
        }

        return $document->robots !== '' ? $document->robots : 'index, follow';
    }

    private function ogImage(SeoDocument $document, string $locale): string
    {
        if ($document->images !== []) {
            return $document->images[0];
        }

        $fallback = trim((string) $this->settings->get('seo.default_og_image', ''));
        if ($fallback !== '') {
            return $this->urls->absolutePath($fallback, $locale);
        }

        return $this->urls->absolutePath('/CPalius.png', $locale);
    }

    private function ogLocale(string $locale): string
    {
        return match ($locale) {
            'tr' => 'tr_TR',
            'en' => 'en_US',
            default => $locale.'_'.strtoupper($locale),
        };
    }

    /**
     * @param list<string> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
