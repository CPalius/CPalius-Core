<?php

declare(strict_types=1);

namespace App\Core\Font;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Installs a typeface from a URL into public/fonts/.
 *
 * Two shapes are accepted, because those are the two things people actually
 * have in the clipboard:
 *
 *   - a font-service stylesheet (fonts.googleapis.com/css2?family=Inter…),
 *     whose @font-face blocks are parsed and whose files are fetched; and
 *   - a direct link to a .woff2/.woff/.ttf/.otf file.
 *
 * Everything is downloaded once and served from this installation afterwards.
 * That is the point of the feature: a page that pulls its fonts from a third
 * party tells that third party who is reading it, on every request, forever.
 *
 * An operator pasting a URL is a request this server makes on their behalf, so
 * the same SSRF rules the forum's link unfurler uses apply here — scheme,
 * hostname and every resolved address are checked before a socket opens, and
 * redirects are followed by hand so each hop is checked too.
 */
final class FontInstaller
{
    private const TIMEOUT = 15;
    private const MAX_REDIRECTS = 3;

    /** A stylesheet listing hundreds of faces is not a typeface, it is a mistake. */
    private const MAX_FACES = 60;
    private const MAX_FILE_BYTES = 6_000_000;
    private const MAX_CSS_BYTES = 512_000;

    /**
     * Asking as a current browser matters: font services answer the same URL
     * with woff2 or with twenty-year-old formats depending on who asks.
     */
    private const CSS_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct(
        private readonly FontLibrary $library,
    ) {
    }

    /**
     * @param string      $url        stylesheet or font-file URL
     * @param string|null $familyName operator's override for the family name
     *
     * @throws FontInstallException on anything that would leave a half-installed family
     */
    public function installFromUrl(string $url, ?string $familyName = null): FontFamily
    {
        $url = trim($url);

        if ($this->resolvePublicTarget($url) === null) {
            throw new FontInstallException('aacp.fonts.error.url_not_public');
        }

        $extension = strtolower(pathinfo((string) (parse_url($url, \PHP_URL_PATH) ?? ''), \PATHINFO_EXTENSION));

        $faces = \in_array($extension, FontLibrary::ALLOWED_EXTENSIONS, true)
            ? $this->facesFromSingleFile($url, $extension)
            : $this->facesFromStylesheet($url);

        if ($faces === []) {
            throw new FontInstallException('aacp.fonts.error.no_faces');
        }

        $name = trim((string) $familyName);
        if ($name === '') {
            $name = $faces[0]['family'];
        }

        return $this->write($name, $url, $faces);
    }

    /**
     * @return list<array{family: string, weight: string, style: string, url: string, format: string, unicodeRange: ?string}>
     */
    private function facesFromSingleFile(string $url, string $extension): array
    {
        $name = pathinfo((string) (parse_url($url, \PHP_URL_PATH) ?? ''), \PATHINFO_FILENAME);

        return [[
            'family' => $name !== '' ? ucwords(str_replace(['-', '_'], ' ', $name)) : 'Custom',
            'weight' => '400',
            'style' => 'normal',
            'url' => $url,
            'format' => $extension === 'ttf' ? 'truetype' : ($extension === 'otf' ? 'opentype' : $extension),
            'unicodeRange' => null,
        ]];
    }

    /**
     * @return list<array{family: string, weight: string, style: string, url: string, format: string, unicodeRange: ?string}>
     */
    private function facesFromStylesheet(string $url): array
    {
        $css = $this->fetch($url, self::MAX_CSS_BYTES, self::CSS_USER_AGENT);

        if (!preg_match_all('/@font-face\s*\{(.*?)\}/s', $css, $blocks)) {
            throw new FontInstallException('aacp.fonts.error.not_a_stylesheet');
        }

        $faces = [];

        foreach ($blocks[1] as $block) {
            $family = $this->cssValue($block, 'font-family');
            $src = $this->cssValue($block, 'src');

            if ($family === null || $src === null) {
                continue;
            }

            // Prefer woff2; a block usually lists several formats and there is no
            // reason to store the ones every supported browser ignores.
            if (!preg_match('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)\s*format\(\s*[\'"]?woff2[\'"]?\s*\)/i', $src, $m)
                && !preg_match('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $src, $m)) {
                continue;
            }

            $fileUrl = $this->absolutize(trim($m[1]), $url);
            if ($fileUrl === null) {
                continue;
            }

            $extension = strtolower(pathinfo((string) (parse_url($fileUrl, \PHP_URL_PATH) ?? ''), \PATHINFO_EXTENSION));
            if (!\in_array($extension, FontLibrary::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $faces[] = [
                'family' => trim($family, " \t\n\r\0\x0B\"'"),
                'weight' => $this->cssValue($block, 'font-weight') ?? '400',
                'style' => $this->cssValue($block, 'font-style') ?? 'normal',
                'url' => $fileUrl,
                'format' => $extension === 'ttf' ? 'truetype' : ($extension === 'otf' ? 'opentype' : $extension),
                'unicodeRange' => $this->cssValue($block, 'unicode-range'),
            ];

            if (\count($faces) >= self::MAX_FACES) {
                break;
            }
        }

        return $faces;
    }

    /**
     * @param list<array{family: string, weight: string, style: string, url: string, format: string, unicodeRange: ?string}> $faces
     */
    private function write(string $name, string $source, array $faces): FontFamily
    {
        $slug = $this->library->sanitizeSlug($name);
        if ($slug === '') {
            throw new FontInstallException('aacp.fonts.error.bad_name');
        }

        $this->library->ensureRoot();
        $dir = $this->library->rootPath().'/'.$slug;

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new FontInstallException('aacp.fonts.error.not_writable');
        }

        $manifest = ['family' => $name, 'source' => $source, 'installed_at' => date('c'), 'files' => []];
        $written = [];

        try {
            $index = 0;
            foreach ($faces as $face) {
                $fileName = $this->fileName($slug, $face, $index++);
                $bytes = $this->fetch($face['url'], self::MAX_FILE_BYTES, self::CSS_USER_AGENT);

                if (!$this->looksLikeFont($bytes)) {
                    throw new FontInstallException('aacp.fonts.error.not_a_font');
                }

                if (file_put_contents($dir.'/'.$fileName, $bytes) === false) {
                    throw new FontInstallException('aacp.fonts.error.not_writable');
                }

                $written[] = $dir.'/'.$fileName;
                $manifest['files'][$fileName] = [
                    'weight' => $face['weight'],
                    'style' => $face['style'],
                    'unicodeRange' => $face['unicodeRange'],
                ];
            }

            file_put_contents(
                $dir.'/'.FontLibrary::MANIFEST,
                json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            // A family missing half its weights is worse than one that never
            // installed: the site would render in a fallback for some text and
            // the real face for the rest, and nothing would say why.
            foreach ($written as $path) {
                @unlink($path);
            }
            @unlink($dir.'/'.FontLibrary::MANIFEST);
            @rmdir($dir);

            throw $e instanceof FontInstallException ? $e : new FontInstallException('aacp.fonts.error.download_failed');
        }

        $family = $this->library->read($slug);

        if ($family === null) {
            throw new FontInstallException('aacp.fonts.error.download_failed');
        }

        return $family;
    }

    /**
     * @param array{weight: string, style: string, format: string, unicodeRange: ?string} $face
     */
    private function fileName(string $slug, array $face, int $index): string
    {
        $extension = match ($face['format']) {
            'truetype' => 'ttf',
            'opentype' => 'otf',
            default => $face['format'],
        };

        // The index keeps subsets apart: Google splits one weight across latin,
        // latin-ext, cyrillic and so on, all with the same weight and style.
        return sprintf('%s-%s%s-%d.%s', $slug, $face['weight'], $face['style'] === 'italic' ? 'i' : '', $index, $extension);
    }

    /**
     * Magic numbers, so a redirect to an HTML error page cannot be stored as a
     * font and then fail silently in every visitor's browser.
     */
    private function looksLikeFont(string $bytes): bool
    {
        if (\strlen($bytes) < 16) {
            return false;
        }

        $head = substr($bytes, 0, 4);

        return \in_array($head, ["wOF2", "wOFF", "OTTO", "true", "ttcf"], true)
            || $head === "\x00\x01\x00\x00";
    }

    private function cssValue(string $block, string $property): ?string
    {
        if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/i', $block, $m) !== 1) {
            return null;
        }

        $value = trim($m[1]);

        return $value === '' ? null : $value;
    }

    private function absolutize(string $candidate, string $base): ?string
    {
        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            return $candidate;
        }

        if (str_starts_with($candidate, '//')) {
            return 'https:'.$candidate;
        }

        $parts = parse_url($base);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($candidate, '/') ? $origin.$candidate : null;
    }

    /**
     * Fetches a URL, following redirects by hand so every hop is re-validated.
     */
    private function fetch(string $url, int $maxBytes, string $userAgent): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $target = $this->resolvePublicTarget($url);
            if ($target === null) {
                throw new FontInstallException('aacp.fonts.error.url_not_public');
            }

            try {
                $client = HttpClient::create([
                    'timeout' => self::TIMEOUT,
                    'max_redirects' => 0,
                    'headers' => ['User-Agent' => $userAgent],
                    // Pin the address that was just checked, so DNS cannot answer
                    // differently between the check and the connection.
                    'resolve' => [$target['host'] => $target['ip']],
                ]);

                $response = $client->request('GET', $url);
                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? '';
                    $next = $this->absolutize(trim($location), $url);

                    if ($next === null) {
                        throw new FontInstallException('aacp.fonts.error.download_failed');
                    }

                    $url = $next;
                    continue;
                }

                if ($status !== 200) {
                    throw new FontInstallException('aacp.fonts.error.download_failed');
                }

                $body = $response->getContent();

                if (\strlen($body) > $maxBytes) {
                    throw new FontInstallException('aacp.fonts.error.too_large');
                }

                return $body;
            } catch (HttpExceptionInterface) {
                throw new FontInstallException('aacp.fonts.error.download_failed');
            }
        }

        throw new FontInstallException('aacp.fonts.error.download_failed');
    }

    /**
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

        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? ['host' => $host, 'ip' => $host] : null;
        }

        $ips = @gethostbynamel($host) ?: [];
        if ($ips === []) {
            return null;
        }

        // One private answer in the set is a rebinding attempt, not a mixed host.
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return (bool) filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
        }

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return (bool) filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }
}
