<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Minifies HTML/CSS/JS, compresses local images, optional source shield.
 */
final class OriginHtmlProcessor
{
    public function __construct(
        private readonly OriginCacheStore $store,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array{minify: bool, compress_assets: bool, compress_images: bool, shield: bool} $flags
     */
    public function process(string $html, array $flags): string
    {
        if ($flags['minify']) {
            $html = $this->minifyHtml($html);
        }
        if ($flags['compress_assets']) {
            $html = $this->rewriteAssets($html);
        }
        if ($flags['compress_images']) {
            $html = $this->rewriteImages($html);
        }

        $html = $this->injectBanner($html);

        if ($flags['shield']) {
            try {
                $html = $this->shield($html);
            } catch (\JsonException) {
                // Keep the minified HTML when the shield packer cannot encode it.
            }
        }

        return $html;
    }

    private function injectBanner(string $html): string
    {
        $banner = OriginCacheStore::BANNER;
        if (str_contains($html, $banner)) {
            return $html;
        }
        if (preg_match('/<head[^>]*>/i', $html) === 1) {
            return preg_replace('/<head[^>]*>/i', '$0'.$banner, $html, 1) ?? $html;
        }

        return $banner.$html;
    }

    private function minifyHtml(string $html): string
    {
        $html = preg_replace('/<!--(?!\[if)(?! CPalius).*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        $html = preg_replace("/[ \t]+/", ' ', $html) ?? $html;

        return trim($html);
    }

    private function rewriteAssets(string $html): string
    {
        return preg_replace_callback(
            '/\b(?:href|src)=["\'](\/(?:themes|assets)\/[^"\']+\.(?:css|js))["\']/i',
            function (array $m): string {
                $url = $m[1];
                $attr = str_starts_with(strtolower($m[0]), 'href') ? 'href' : 'src';
                $rewritten = $this->compressPublicAsset($url);

                return $attr.'="'.($rewritten ?? $url).'"';
            },
            $html,
        ) ?? $html;
    }

    private function rewriteImages(string $html): string
    {
        return preg_replace_callback(
            '/\b(?:src|data-src)=["\'](\/(?:uploads|themes)\/[^"\']+\.(?:jpe?g|png|gif|webp))["\']/i',
            function (array $m): string {
                $url = $m[1];
                $attr = str_contains(strtolower($m[0]), 'data-src') ? 'data-src' : 'src';
                $rewritten = $this->compressPublicImage($url);

                return $attr.'="'.($rewritten ?? $url).'"';
            },
            $html,
        ) ?? $html;
    }

    private function compressPublicAsset(string $url): ?string
    {
        $path = $this->publicPath($url);
        if ($path === null) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $min = $ext === 'css' ? $this->minifyCss($raw) : $this->minifyJs($raw);
        $name = hash('sha256', $min).'.'.$ext;

        return $this->store->putAsset($name, $min);
    }

    private function compressPublicImage(string $url): ?string
    {
        $path = $this->publicPath($url);
        if ($path === null || !\function_exists('imagewebp')) {
            return null;
        }
        $blob = $this->toWebp($path);
        if ($blob === null) {
            return null;
        }
        $name = hash('sha256', $blob).'.webp';

        return $this->store->putImage($name, $blob);
    }

    private function toWebp(string $path): ?string
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if ($image === false) {
            return null;
        }
        if (\function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        @imagealphablending($image, true);
        @imagesavealpha($image, true);
        ob_start();
        $ok = @imagewebp($image, null, 78);
        $blob = ob_get_clean();
        imagedestroy($image);
        if (!$ok || !\is_string($blob) || $blob === '') {
            return null;
        }

        return $blob;
    }

    private function publicPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!\is_string($path) || str_contains($path, '..')) {
            return null;
        }
        $full = $this->projectDir.\DIRECTORY_SEPARATOR.'public'.str_replace('/', \DIRECTORY_SEPARATOR, $path);

        return is_file($full) ? $full : null;
    }

    private function minifyCss(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = str_replace(['; ', ' {', '{ ', ' }', '} ', ': '], [';', '{', '{', '}', '}', ':'], $css);

        return trim($css);
    }

    private function minifyJs(string $js): string
    {
        $js = preg_replace('#/\*.*?\*/#s', '', $js) ?? $js;
        $js = preg_replace('/^[ \t]*\/\/.*$/m', '', $js) ?? $js;
        $js = preg_replace("/\n{2,}/", "\n", $js) ?? $js;

        return trim($js);
    }

    private function shield(string $html): string
    {
        $lang = 'tr';
        if (preg_match('/<html[^>]*lang=["\']([^"\']+)["\']/i', $html, $m) === 1) {
            $lang = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $title = 'CPalius';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $description = '';
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m) === 1) {
            $description = $m[1];
        }
        $payload = json_encode($html, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $descTag = $description !== ''
            ? '<meta name="description" content="'.htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            : '';

        return '<!DOCTYPE html><html lang="'.$lang.'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$titleEsc.'</title>'.$descTag
            .'<meta name="generator" content="CPalius">'
            .OriginCacheStore::BANNER
            .'</head><body><script>document.open();document.write('.$payload.');document.close();</script>'
            .'<noscript>JavaScript is required.</noscript></body></html>';
    }
}
