<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap;

final class SitemapXmlRenderer
{
    /**
     * @param list<array{loc: string, lastmod?: string}> $entries
     */
    public function index(array $entries): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $entry) {
            $body .= '  <sitemap>'."\n";
            $body .= '    <loc>'.$this->esc($entry['loc']).'</loc>'."\n";
            if (($entry['lastmod'] ?? '') !== '') {
                $body .= '    <lastmod>'.$this->esc($entry['lastmod']).'</lastmod>'."\n";
            }
            $body .= '  </sitemap>'."\n";
        }
        $body .= '</sitemapindex>'."\n";

        return $body;
    }

    /**
     * @param iterable<SitemapUrl> $urls
     */
    public function urlset(iterable $urls): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $body .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        $body .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        $body .= ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">'."\n";

        foreach ($urls as $url) {
            $body .= "  <url>\n";
            $body .= '    <loc>'.$this->esc($url->loc)."</loc>\n";
            if ($url->lastmod instanceof \DateTimeInterface) {
                $body .= '    <lastmod>'.$url->lastmod->format('Y-m-d')."</lastmod>\n";
            }
            $body .= '    <changefreq>'.$this->esc($url->changefreq)."</changefreq>\n";
            $body .= '    <priority>'.$this->esc($url->priority)."</priority>\n";
            foreach ($url->alternates as $code => $href) {
                $body .= '    <xhtml:link rel="alternate" hreflang="'.$this->esc((string) $code).'" href="'.$this->esc($href).'"/>'."\n";
            }
            foreach ($url->images as $image) {
                $body .= '    <image:image><image:loc>'.$this->esc($image)."</image:loc></image:image>\n";
            }
            if ($url->videoUrl !== null && $url->videoUrl !== '') {
                $body .= "    <video:video>\n";
                $body .= '      <video:content_loc>'.$this->esc($url->videoUrl)."</video:content_loc>\n";
                $body .= '      <video:title>'.$this->esc($url->videoTitle ?: $url->loc)."</video:title>\n";
                $body .= "    </video:video>\n";
            }
            $body .= "  </url>\n";
        }

        $body .= '</urlset>'."\n";

        return $body;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
