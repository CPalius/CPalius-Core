<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns standalone social/video URLs into iframe or widget markup at render time.
 */
final class ForumMediaEmbedder
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array{type: string, html: string}|null
     */
    public function embed(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);

        if ($this->isYouTube($host)) {
            $id = $this->youtubeId($url, $path, $query);
            if ($id === null) {
                return null;
            }

            return $this->frame('youtube', 'https://www.youtube-nocookie.com/embed/'.$id, '16/9');
        }

        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#/(?:video/)?(\d+)#', $path, $m)) {
                return $this->frame('vimeo', 'https://player.vimeo.com/video/'.$m[1], '16/9');
            }

            return null;
        }

        if ($host === 'dailymotion.com' || $host === 'dai.ly') {
            $id = $host === 'dai.ly'
                ? trim($path, '/')
                : (preg_match('#/video/([a-zA-Z0-9]+)#', $path, $m) ? $m[1] : '');
            if ($id !== '') {
                return $this->frame('dailymotion', 'https://www.dailymotion.com/embed/video/'.$id, '16/9');
            }

            return null;
        }

        if ($host === 'tiktok.com') {
            if (preg_match('#/video/(\d+)#', $path, $m)) {
                return $this->frame('tiktok', 'https://www.tiktok.com/embed/v2/'.$m[1], '9/16', 'forum-embed--portrait');
            }

            return null;
        }

        if ($host === 'instagram.com') {
            if (preg_match('#/(p|reel|tv)/([A-Za-z0-9_-]+)#', $path, $m)) {
                $permalink = 'https://www.instagram.com/'.$m[1].'/'.$m[2].'/';

                return [
                    'type' => 'instagram',
                    'html' => '<blockquote class="instagram-media forum-embed forum-embed--instagram" data-instgrm-permalink="'
                        .$this->e($permalink).'" data-instgrm-version="14"></blockquote>',
                ];
            }

            return null;
        }

        if ($host === 'twitter.com' || $host === 'x.com' || $host === 'mobile.twitter.com') {
            if (preg_match('#/status(?:es)?/(\d+)#', $path, $m)) {
                $tweetUrl = 'https://twitter.com/i/status/'.$m[1];

                return [
                    'type' => 'twitter',
                    'html' => '<div class="forum-embed forum-embed--twitter"><blockquote class="twitter-tweet" data-dnt="true"><a href="'
                        .$this->e($tweetUrl).'">'.$this->e($tweetUrl).'</a></blockquote></div>',
                ];
            }

            return null;
        }

        if ($host === 'open.spotify.com') {
            if (preg_match('#/(track|album|playlist|episode|show)/([A-Za-z0-9]+)#', $path, $m)) {
                $ratio = $m[1] === 'track' || $m[1] === 'episode' ? 'auto' : '1/1';
                $class = $m[1] === 'track' || $m[1] === 'episode' ? 'forum-embed--spotify-track' : '';

                return $this->frame('spotify', 'https://open.spotify.com/embed/'.$m[1].'/'.$m[2], $ratio, $class);
            }

            return null;
        }

        if ($host === 'soundcloud.com') {
            $src = 'https://w.soundcloud.com/player/?url='.rawurlencode($url).'&color=%23ff5500&inverse=false&auto_play=false&show_user=true';

            return $this->frame('soundcloud', $src, 'auto', 'forum-embed--soundcloud');
        }

        if ($host === 'twitch.tv' || $host === 'clips.twitch.tv' || $host === 'player.twitch.tv') {
            $parent = $this->requestStack->getCurrentRequest()?->getHost() ?: 'localhost';
            if ($host === 'clips.twitch.tv' || str_contains($path, '/clip/')) {
                $slug = $host === 'clips.twitch.tv' ? trim($path, '/') : (preg_match('#/clip/([^/]+)#', $path, $m) ? $m[1] : '');
                if ($slug !== '') {
                    return $this->frame('twitch', 'https://clips.twitch.tv/embed?clip='.rawurlencode($slug).'&parent='.rawurlencode($parent), '16/9');
                }
            }
            if (preg_match('#/videos/(\d+)#', $path, $m)) {
                return $this->frame('twitch', 'https://player.twitch.tv/?video='.$m[1].'&parent='.rawurlencode($parent), '16/9');
            }

            return null;
        }

        if ($host === 'streamable.com' && preg_match('#^/([a-z0-9]+)#i', $path, $m)) {
            return $this->frame('streamable', 'https://streamable.com/e/'.$m[1], '16/9');
        }

        if ($this->isDirectImage($url, $path)) {
            return [
                'type' => 'image',
                'html' => '<a class="forum-lightbox forum-embed forum-embed--image" href="'.$this->e($url).'"><img src="'
                    .$this->e($url).'" alt="" loading="lazy"></a>',
            ];
        }

        return null;
    }

    private function isYouTube(string $host): bool
    {
        return \in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com'], true);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function youtubeId(string $url, string $path, array $query): ?string
    {
        $v = $query['v'] ?? null;
        if (\is_string($v) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $v)) {
            return $v;
        }
        if (preg_match('#youtu\.be/([a-zA-Z0-9_-]{11})#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/(?:embed|shorts|live)/([a-zA-Z0-9_-]{11})#', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    private function isDirectImage(string $url, string $path): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|bmp)(\?|#|$)/i', $path !== '' ? $path : $url);
    }

    /**
     * @return array{type: string, html: string}
     */
    private function frame(string $type, string $src, string $ratio, string $extraClass = ''): array
    {
        $class = trim('forum-embed forum-embed--'.$type.' '.$extraClass);
        $style = $ratio === 'auto' ? '' : ' style="--forum-embed-ratio:'.$this->e($ratio).'"';

        return [
            'type' => $type,
            'html' => '<div class="'.$this->e($class).'"'.$style.'><iframe src="'.$this->e($src)
                .'" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>',
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
