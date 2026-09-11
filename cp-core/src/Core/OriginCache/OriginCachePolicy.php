<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Performance\VarnishCachePolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin HTML cache is anonymous GET only; never stores admin, auth, or form endpoints.
 */
final class OriginCachePolicy
{
    public const DEFAULT_TTL = 86400;

    public const DEFAULT_EXCLUDES = VarnishCachePolicy::DEFAULT_EXCLUDES;

    /** @var list<string> */
    private const EXTRA_PRIVATE = [
        '/__cp',
        '/page-cache',
        '/uploads',
        '/themes',
    ];

    /** @var list<string> */
    private const SKIP_SEGMENTS = [
        '/ara',
        '/yeni',
        '/cevrimici',
        '/bildirimler',
        '/yanit',
        '/duzenle',
        '/moderate',
        '/begen',
        '/rapor',
        '/yorum',
    ];

    public function __construct(
        private readonly VarnishCachePolicy $varnishPolicy,
    ) {
    }

    public function normalizeTtl(mixed $ttl): int
    {
        $ttl = (int) $ttl;

        return $ttl > 0 ? min($ttl, 604800) : self::DEFAULT_TTL;
    }

    /**
     * Serve-time gate: never replay a snapshot when the browser has an identity cookie.
     */
    public function isCacheableRequest(Request $request): bool
    {
        return $this->isCacheableWriteRequest($request) && !$this->hasIdentityCookie($request);
    }

    /**
     * Write-time gate: anonymous GET HTML may be snapshotted even if a leftover guest session cookie exists.
     */
    public function isCacheableWriteRequest(Request $request): bool
    {
        if (!$request->isMethod('GET') || $request->isXmlHttpRequest()) {
            return false;
        }

        return $this->isPublicCacheablePath($request->getPathInfo());
    }

    public function isPublicCacheablePath(string $path): bool
    {
        if ($this->varnishPolicy->isHardPrivatePath($path)) {
            return false;
        }
        if ($this->varnishPolicy->matchesAnyPrefix($path, self::EXTRA_PRIVATE)) {
            return false;
        }
        foreach (self::SKIP_SEGMENTS as $segment) {
            if (str_contains($path, $segment)) {
                return false;
            }
        }

        return true;
    }

    public function hasIdentityCookie(Request $request): bool
    {
        if ($request->cookies->has('REMEMBERME')) {
            return true;
        }

        $sessionName = $request->hasSession() ? $request->getSession()->getName() : session_name();
        if (\is_string($sessionName) && $sessionName !== '' && $request->cookies->has($sessionName)) {
            return true;
        }

        return $request->cookies->has('PHPSESSID');
    }

    /**
     * @param list<string> $excludes
     */
    public function parseExcludes(string $raw): array
    {
        return $this->varnishPolicy->parseExcludes($raw);
    }

    /**
     * @param list<string> $excludes
     */
    public function isExcluded(string $path, array $excludes): bool
    {
        return $this->varnishPolicy->isExcludedPath($path, $excludes);
    }

    public function isCacheableResponse(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return false;
        }
        if ($response->headers->has('Set-Cookie')) {
            return false;
        }

        return true;
    }
}
