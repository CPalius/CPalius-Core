<?php

declare(strict_types=1);

namespace App\Core\Routing;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every generated URL (path(), url(), redirects, mails) carries the operator's
 * panel addresses. Matching is untouched: the kernel already translated the request.
 */
#[AsDecorator(decorates: 'router')]
final class PanelPathRouter implements RouterInterface, RequestMatcherInterface, WarmableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly RouterInterface&RequestMatcherInterface $inner,
    ) {
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $url = $this->inner->generate($name, $parameters, $referenceType);
        $map = PanelPaths::map();
        if ($map === []) {
            return $url;
        }

        $base = $this->inner->getContext()->getBaseUrl();
        // Split off "scheme://host" so only the path is compared.
        if (preg_match('~^((?:[a-z][a-z0-9+.-]*:)?//[^/]+)(/.*)$~i', $url, $m) === 1) {
            return $m[1].PanelPaths::toPublic($m[2], $base, $map);
        }

        return PanelPaths::toPublic($url, $base, $map);
    }

    public function match(string $pathinfo): array
    {
        return $this->inner->match($pathinfo);
    }

    public function matchRequest(Request $request): array
    {
        return $this->inner->matchRequest($request);
    }

    public function setContext(RequestContext $context): void
    {
        $this->inner->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->inner->getContext();
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->inner->getRouteCollection();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }
}
