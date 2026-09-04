<?php

declare(strict_types=1);

namespace Modules\Seo\Engine;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Absolute public URLs from seo.public_base_url, else the current request host.
 */
final class SeoUrlBuilder
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly RequestContext $requestContext,
    ) {
    }

    public function baseUrl(?string $locale = null): string
    {
        $configured = rtrim((string) $this->settings->getForLocale('seo.public_base_url', $locale ?? $this->currentLocale(), ''), '/');
        if ($configured === '') {
            $configured = rtrim((string) $this->settings->get('seo.public_base_url', ''), '/');
        }
        if ($configured !== '') {
            return $configured;
        }

        $request = $this->requestStack->getCurrentRequest();

        return $request instanceof Request ? $request->getSchemeAndHttpHost() : '';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function absolute(string $route, array $parameters = [], ?string $locale = null): string
    {
        if ($locale !== null && !isset($parameters['_locale'])) {
            $parameters['_locale'] = $locale;
        }

        $this->applyContext($locale);

        return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function absolutePath(string $path, ?string $locale = null): string
    {
        $base = $this->baseUrl($locale);
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $base.($path[0] === '/' ? $path : '/'.$path);
    }

    public function assetUrl(string $storageKey, ?string $locale = null): string
    {
        return $this->absolutePath('/uploads/'.ltrim($storageKey, '/'), $locale);
    }

    public function currentCanonical(?string $locale = null): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return $this->baseUrl($locale).'/';
        }

        $path = $request->getPathInfo();
        $canonical = $this->absolutePath($path, $locale);
        $page = $request->query->getInt('page', 1);
        if ($page > 1) {
            $canonical .= '?page='.$page;
        }

        return $canonical;
    }

    private function currentLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request instanceof Request ? (string) $request->getLocale() : 'en';
    }

    private function applyContext(?string $locale): void
    {
        $base = $this->baseUrl($locale);
        if ($base === '') {
            return;
        }

        $parts = parse_url($base);
        if (!\is_array($parts) || empty($parts['host'])) {
            return;
        }

        $this->requestContext->setScheme($parts['scheme'] ?? 'https');
        $this->requestContext->setHost($parts['host']);
        $port = (int) ($parts['port'] ?? 0);
        if (($parts['scheme'] ?? 'https') === 'https') {
            $this->requestContext->setHttpsPort($port > 0 ? $port : 443);
        } else {
            $this->requestContext->setHttpPort($port > 0 ? $port : 80);
        }
        $this->requestContext->setBaseUrl(rtrim((string) ($parts['path'] ?? ''), '/'));
    }
}
