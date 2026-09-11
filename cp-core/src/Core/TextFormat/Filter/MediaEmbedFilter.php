<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Filter;

use App\Core\TextFormat\TextFilterContext;
use App\Core\TextFormat\TextFilterInterface;

/**
 * Turns allowlisted YouTube/Vimeo URLs into sandboxed iframes on OUTPUT.
 * Iframes are never stored (HtmlRestrictFilter drops them on save) — this is
 * the only path that can emit one, and the src host is a closed allowlist.
 * Drupal's media-embed is a render-pipeline plugin plus oEmbed fetch; we skip
 * the network hop (T4.3) and build the player URL from a captured id, so a
 * compromised oEmbed endpoint cannot inject markup.
 */
final class MediaEmbedFilter implements TextFilterInterface
{
    public const ID = 'media_embed';

    private const YOUTUBE = '~(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{11})~';
    private const VIMEO = '~(?:https?://)?(?:www\.)?vimeo\.com/(?:video/)?([0-9]{6,12})~';

    public function id(): string
    {
        return self::ID;
    }

    public function phases(): array
    {
        return [TextFilterContext::PHASE_OUTPUT];
    }

    public function process(string $text, TextFilterContext $context): string
    {
        $text = preg_replace_callback(
            '~<a[^>]+href="([^"]+)"[^>]*>[^<]*</a>~i',
            fn (array $m): string => $this->embed($m[1]) ?? $m[0],
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            self::YOUTUBE,
            function (array $m): string {
                return $this->youtube((string) $m[1]);
            },
            $text,
        ) ?? $text;

        return preg_replace_callback(
            self::VIMEO,
            function (array $m): string {
                return $this->vimeo((string) $m[1]);
            },
            $text,
        ) ?? $text;
    }

    private function embed(string $url): ?string
    {
        if (preg_match(self::YOUTUBE, $url, $m) === 1) {
            return $this->youtube($m[1]);
        }
        if (preg_match(self::VIMEO, $url, $m) === 1) {
            return $this->vimeo($m[1]);
        }

        return null;
    }

    private function youtube(string $id): string
    {
        $src = 'https://www.youtube-nocookie.com/embed/'.rawurlencode($id);

        return $this->iframe($src, 'YouTube');
    }

    private function vimeo(string $id): string
    {
        $src = 'https://player.vimeo.com/video/'.rawurlencode($id);

        return $this->iframe($src, 'Vimeo');
    }

    private function iframe(string $src, string $title): string
    {
        return sprintf(
            '<div class="cp-embed"><iframe src="%s" title="%s" loading="lazy" sandbox="allow-scripts allow-same-origin allow-presentation" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>',
            htmlspecialchars($src, \ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($title, \ENT_QUOTES, 'UTF-8'),
        );
    }
}
