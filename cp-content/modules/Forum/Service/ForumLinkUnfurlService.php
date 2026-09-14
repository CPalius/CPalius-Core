<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumLinkPreview;
use Modules\Forum\Repository\ForumLinkPreviewRepository;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * XenForo-style link unfurl: fetch Open Graph metadata and cache it.
 */
final class ForumLinkUnfurlService
{
    private const MAX_BYTES = 400_000;
    private const TIMEOUT = 4;
    private const FAILED_RETRY_AFTER = 86400;

    /** Redirect hops followed, each validated and pinned before it is requested. */
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly ForumLinkPreviewRepository $previewRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    public function findCached(string $url): ?ForumLinkPreview
    {
        return $this->previewRepository->findOneByHash(self::hash($url));
    }

    public function preview(string $url, bool $fetch = false): ?ForumLinkPreview
    {
        $url = self::normalize($url);
        if (!$this->isPublicHttpUrl($url)) {
            return null;
        }

        $row = $this->findCached($url);
        if ($row instanceof ForumLinkPreview) {
            if ($row->isReady()) {
                return $row;
            }
            $age = time() - $row->getFetchedAt()->getTimestamp();
            if ($age < self::FAILED_RETRY_AFTER || !$fetch) {
                return $row;
            }
        }

        if (!$fetch) {
            return $row;
        }

        if (!$row instanceof ForumLinkPreview) {
            $row = new ForumLinkPreview(self::hash($url), $url);
            $this->entityManager->persist($row);
        }

        $fetched = $this->fetchMetadata($url);
        if ($fetched === null) {
            $row->markFailed();
        } else {
            $row->markReady(
                $fetched['title'],
                $fetched['description'],
                $fetched['image'],
                $fetched['siteName'],
                $fetched['favicon'],
            );
        }
        $this->entityManager->flush();

        return $row;
    }

    /**
     * @return array{ok: bool, url: string, title: ?string, description: ?string, image: ?string, siteName: ?string, favicon: ?string, host: string}
     */
    public function toCardPayload(?ForumLinkPreview $preview, string $fallbackUrl): array
    {
        $url = $preview?->getUrl() ?: $fallbackUrl;
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return [
            'ok' => $preview?->isReady() ?? false,
            'url' => $url,
            'title' => $preview?->getTitle(),
            'description' => $preview?->getDescription(),
            'image' => $preview?->getImageUrl(),
            'siteName' => $preview?->getSiteName() ?: $host,
            'favicon' => $preview?->getFaviconUrl(),
            'host' => $host,
        ];
    }

    public function isPublicHttpUrl(string $url): bool
    {
        return $this->resolvePublicTarget($url) !== null;
    }

    /**
     * Validates the URL and returns the IP to pin, so the client does not resolve DNS again.
     *
     * @return array{host: string, ip: string}|null
     */
    private function resolvePublicTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? ['host' => $host, 'ip' => $host] : null;
        }

        $ips = @gethostbynamel($host) ?: [];
        if ($ips === []) {
            return null;
        }

        // All answers must be public; one private IP in the set is a rebinding attempt.
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string, siteName: ?string, favicon: ?string}|null
     */
    private function fetchMetadata(string $url): ?array
    {
        $fetched = $this->downloadHtml($url);
        if ($fetched === null) {
            return null;
        }

        $html = $fetched['html'];
        $finalUrl = $fetched['url'];
        $meta = $this->parseHtml($html, $finalUrl);
        if ($meta['title'] === null && $meta['description'] === null && $meta['image'] === null) {
            $host = preg_replace('/^www\./', '', strtolower((string) (parse_url($finalUrl, PHP_URL_HOST) ?? ''))) ?? '';
            $meta['title'] = $host !== '' ? $host : $finalUrl;
        }

        return $meta;
    }

    /**
     * Fetches HTML with max_redirects=0; each Location is validated and IP-pinned before the next hop.
     *
     * @return array{html: string, url: string}|null
     */
    private function downloadHtml(string $url): ?array
    {
        $client = HttpClient::create([
            'timeout' => self::TIMEOUT,
            // Zero: this method owns redirect handling.
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; CPaliusForum/1.0; +https://www.cpalius.com)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'tr,en;q=0.8',
            ],
        ]);

        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $target = $this->resolvePublicTarget($current);
            if ($target === null) {
                return null;
            }

            try {
                $response = $client->request('GET', $current, [
                    'max_duration' => self::TIMEOUT + 1,
                    'resolve' => [$target['host'] => $target['ip']],
                ]);

                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    // getHeaders(false) so a 3xx is data rather than an exception.
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    if (!\is_string($location) || $location === '') {
                        return null;
                    }

                    $next = $this->resolveRedirectLocation($location, $current);
                    if ($next === null) {
                        return null;
                    }

                    $current = $next;

                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    return null;
                }

                return [
                    'html' => mb_substr($response->getContent(), 0, self::MAX_BYTES),
                    'url' => $current,
                ];
            } catch (ExceptionInterface|\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** Turns a Location header into an absolute URL so the next hop can be validated. */
    private function resolveRedirectLocation(string $location, string $base): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        // Protocol-relative Location changes host; keep only the base scheme.
        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = $parts['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$location;
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string, siteName: ?string, favicon: ?string}
     */
    private function parseHtml(string $html, string $baseUrl): array
    {
        $out = ['title' => null, 'description' => null, 'image' => null, 'siteName' => null, 'favicon' => null];
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $map = [];
        foreach ($xpath->query('//meta') ?: [] as $meta) {
            if (!$meta instanceof \DOMElement) {
                continue;
            }
            $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            $content = trim($meta->getAttribute('content'));
            if ($key !== '' && $content !== '') {
                $map[$key] = $content;
            }
        }

        $out['title'] = $map['og:title'] ?? $map['twitter:title'] ?? null;
        if ($out['title'] === null) {
            $titleNodes = $xpath->query('//title');
            $titleEl = $titleNodes !== false ? $titleNodes->item(0) : null;
            if ($titleEl instanceof \DOMNode) {
                $out['title'] = trim($titleEl->textContent);
            }
        }
        $out['description'] = $map['og:description'] ?? $map['twitter:description'] ?? $map['description'] ?? null;
        $out['siteName'] = $map['og:site_name'] ?? null;
        $image = $map['og:image'] ?? $map['twitter:image'] ?? $map['twitter:image:src'] ?? null;
        $out['image'] = $this->absolutize($image, $baseUrl);

        foreach ($xpath->query('//link[@rel]') ?: [] as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            if (str_contains($rel, 'icon')) {
                $out['favicon'] = $this->absolutize($link->getAttribute('href'), $baseUrl);
                break;
            }
        }
        if ($out['favicon'] === null) {
            $host = parse_url($baseUrl, PHP_URL_SCHEME).'://'.parse_url($baseUrl, PHP_URL_HOST);
            $out['favicon'] = $host.'/favicon.ico';
        }

        return $out;
    }

    private function absolutize(?string $value, string $baseUrl): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $this->isPublicHttpUrl($value) ? $value : null;
        }
        $base = parse_url($baseUrl);
        if (!\is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'];
        if (str_starts_with($value, '/')) {
            return $origin.$value;
        }

        return $origin.'/'.$value;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }
}
