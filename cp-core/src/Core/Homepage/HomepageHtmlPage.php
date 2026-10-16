<?php

declare(strict_types=1);

namespace App\Core\Homepage;

use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Serves the operator's own homepage as a standalone document.
 *
 * See HomepageHtmlGuard for the model: the markup is sanitised again here (the
 * stored value is never trusted to have been checked), and the response carries a
 * Content-Security-Policy that makes the page an opaque-origin, network-less
 * island. The page is real HTML in the real response — not an iframe — so search
 * engines index it like any other page.
 */
final class HomepageHtmlPage
{
    public function __construct(
        private readonly HomepageStore $store,
        private readonly HomepageHtmlGuard $guard,
        private readonly SettingsRegistry $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function response(Request $request, bool $preview = false): Response
    {
        $locale = $request->getLocale();
        $parts = $this->store->html();
        $checked = $this->guard->check($parts);

        // A stored page that no longer passes (rules tighten between releases) is
        // served without its script rather than with it.
        $js = $checked['errors'] === [] ? $checked['js'] : '';
        $css = $checked['css'];
        foreach ($checked['errors'] as $error) {
            if (str_starts_with($error, 'CSS:')) {
                $css = '';
                break;
            }
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $seo = $this->store->seo($locale);
        $siteName = trim((string) $this->settings->getForLocale('core.site_name', $locale, ''));
        $title = $seo['title'] !== '' ? $seo['title'] : ($siteName !== '' ? $siteName : 'CPalius CMF');
        $description = $seo['description'] !== ''
            ? $seo['description']
            : trim((string) $this->settings->getForLocale('core.default_meta_description', $locale, ''));

        $canonical = $this->urls->generate('site_home', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
        $e = static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $head = [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>'.$e($title).'</title>',
            $description !== '' ? '<meta name="description" content="'.$e($description).'">' : '',
            '<meta name="robots" content="'.($seo['noindex'] || $preview ? 'noindex, nofollow' : 'index, follow').'">',
            '<link rel="canonical" href="'.$e($canonical).'">',
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="'.$e($title).'">',
            $description !== '' ? '<meta property="og:description" content="'.$e($description).'">' : '',
            '<meta property="og:url" content="'.$e($canonical).'">',
            $seo['image'] !== '' ? '<meta property="og:image" content="'.$e($this->absolute($request, $seo['image'])).'">' : '',
            '<meta name="twitter:card" content="'.($seo['image'] !== '' ? 'summary_large_image' : 'summary').'">',
        ];
        foreach ($this->localeProvider->getCodes() as $code) {
            $head[] = '<link rel="alternate" hreflang="'.$e($code).'" href="'
                .$e($this->urls->generate('site_home', ['_locale' => $code], UrlGeneratorInterface::ABSOLUTE_URL)).'">';
        }
        $head[] = '<script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteName !== '' ? $siteName : $title,
            'url' => $canonical,
            'inLanguage' => $locale,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG).'</script>';
        if ($css !== '') {
            $head[] = '<style nonce="'.$nonce.'">'.$css.'</style>';
        }

        $body = $checked['html'].($js !== '' ? '<script nonce="'.$nonce.'">'.$js.'</script>' : '');
        $page = '<!doctype html><html lang="'.$e($locale).'"><head>'.implode('', array_filter($head)).'</head><body>'.$body.'</body></html>';

        $response = new Response($page);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        // SecurityHeadersSubscriber never overwrites a header already present, so
        // the site-wide policy does not replace this one.
        if ($preview) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'none'",
            "script-src 'nonce-".$nonce."'",
            "style-src 'nonce-".$nonce."'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' https: data:",
            "media-src 'self' https:",
            "font-src 'self' data:",
            "connect-src 'none'",
            "form-action 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'self'",
            'sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-modals',
        ]);
    }

    private function absolute(Request $request, string $url): string
    {
        return str_starts_with($url, '/') ? $request->getSchemeAndHttpHost().$url : $url;
    }
}
