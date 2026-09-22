<?php

declare(strict_types=1);

namespace Modules\DnsTools\Seo;

use Modules\DnsTools\Catalog\PresentedTool;
use Modules\DnsTools\Service\ToolRegistry;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DnsToolsSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly SeoUrlBuilder $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function priority(): int
    {
        return 70;
    }

    public function supports(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');

        return str_starts_with($route, 'dnstools_');
    }

    public function document(Request $request): ?SeoDocument
    {
        $route = (string) $request->attributes->get('_route', '');
        $locale = (string) $request->getLocale();
        $home = $this->urls->absolute('theme_cpalius_website_home', ['_locale' => $locale], $locale);
        $index = $this->urls->absolute('dnstools_index', ['_locale' => $locale], $locale);

        if ($route === 'dnstools_index') {
            $copy = $this->registry->pageCopy('index', 'dnstools.seo.index', $locale);

            return new SeoDocument(
                headline: $copy['title'],
                description: $copy['description'],
                canonicalPath: $index,
                ogType: 'website',
                contentKind: 'page',
                schemaType: 'WebApplication',
                breadcrumbs: [
                    ['name' => $this->translator->trans('dnstools.breadcrumb.home', [], null, $locale), 'url' => $home],
                ],
                locale: $locale,
                keywords: $copy['keywords'],
                schemaExtra: [
                    'applicationCategory' => 'DeveloperApplication',
                    'operatingSystem' => 'Web',
                ],
            );
        }

        if ($route === 'dnstools_all') {
            $copy = $this->registry->pageCopy('all', 'dnstools.seo.all', $locale);

            return new SeoDocument(
                headline: $copy['title'],
                description: $copy['description'],
                canonicalPath: $this->urls->absolute('dnstools_all', ['_locale' => $locale], $locale),
                ogType: 'website',
                contentKind: 'page',
                schemaType: 'ItemList',
                breadcrumbs: [
                    ['name' => $this->translator->trans('dnstools.seo.index.title', [], null, $locale), 'url' => $index],
                ],
                locale: $locale,
                keywords: $copy['keywords'],
            );
        }

        if ($route === 'dnstools_tool') {
            $slug = (string) $request->attributes->get('slug', '');
            $tool = $this->registry->get($slug);
            if (!$tool instanceof PresentedTool) {
                return null;
            }

            $url = $this->urls->absolute('dnstools_tool', ['_locale' => $locale, 'slug' => $tool->slug], $locale);

            return new SeoDocument(
                headline: $tool->title,
                description: $tool->description,
                canonicalPath: $url,
                ogType: 'website',
                contentKind: 'page',
                schemaType: $tool->definition->schemaType,
                breadcrumbs: [
                    ['name' => $this->translator->trans('dnstools.seo.index.title', [], null, $locale), 'url' => $index],
                ],
                locale: $locale,
                keywords: $tool->keywords,
                schemaExtra: [
                    'applicationCategory' => 'DeveloperApplication',
                    'featureList' => $tool->subtitle,
                ],
            );
        }

        return null;
    }
}
