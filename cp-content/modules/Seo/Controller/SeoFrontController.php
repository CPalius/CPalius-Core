<?php

declare(strict_types=1);

namespace Modules\Seo\Controller;

use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoFrontController
{
    public function __construct(
        private readonly SitemapBuilder $sitemaps,
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
        private readonly LocaleProvider $locales,
    ) {
    }

    #[Route('/sitemap.xml', name: 'seo_sitemap_index', methods: ['GET'])]
    public function sitemapIndex(): Response
    {
        if (!$this->isOn('seo.sitemap_enabled')) {
            return new Response('Sitemap disabled', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $xml = $this->sitemaps->indexXml($this->urls->baseUrl());

        return $this->xml($xml);
    }

    #[Route(
        '/sitemap-{source}-{locale}.xml',
        name: 'seo_sitemap_source',
        methods: ['GET'],
        requirements: ['source' => 'pages|blog|forum|roadmap', 'locale' => '%cpalius.locales_pattern%'],
    )]
    public function sitemapSource(string $source, string $locale): Response
    {
        if (!$this->isOn('seo.sitemap_enabled')) {
            return new Response('Sitemap disabled', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $locale = $this->locales->resolve($locale);
        $xml = $this->sitemaps->sourceXml($source, $locale);

        return $this->xml($xml);
    }

    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
        ];

        if (!$this->isOn('seo.site_indexable')) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            $lines[] = 'Disallow: /admin';
            $lines[] = 'Disallow: /aacp';
            $lines[] = 'Disallow: /login';
            $lines[] = 'Disallow: /account';
            $lines[] = 'Disallow: /hesap';
            $lines[] = 'Disallow: /_fragment';
            foreach ($this->locales->getCodes() as $code) {
                $lines[] = 'Disallow: /'.$code.'/account';
                $lines[] = 'Disallow: /'.$code.'/hesap';
                $lines[] = 'Disallow: /'.$code.'/forum/bildirimler';
                $lines[] = 'Disallow: /'.$code.'/forums/ara';
                $lines[] = 'Disallow: /'.$code.'/blog/ara';
            }
        }

        $extra = trim((string) $this->settings->get('seo.robots_extra', ''));
        if ($extra !== '') {
            $lines[] = $extra;
        }

        if ($this->isOn('seo.sitemap_enabled')) {
            $lines[] = 'Sitemap: '.$this->urls->absolutePath('/sitemap.xml');
        }

        return new Response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function xml(string $xml): Response
    {
        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
