<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Module\ModuleContributionCatalog;
use App\Core\Settings\SettingsRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * When a module is the homepage (homepage.mode), ThemeController serves that module's
 * index at /{_locale} in place. This makes the module's index route mean the same URL
 * everywhere it is generated — menus, canonical, sitemap, pagination, language switcher —
 * and 301s the old /{_locale}/<module> address there, so the page never has two URLs.
 */
#[AsDecorator('router')]
final class HomepageModeRouter implements RouterInterface, RequestMatcherInterface, WarmableInterface, EventSubscriberInterface
{
    public const HOME_ROUTE = 'theme_cpalius_website_home';

    /**
     * @param \Closure(): SettingsRegistry $settings lazy: settings must not be built while the router is
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly RouterInterface&RequestMatcherInterface $inner,
        #[AutowireServiceClosure(SettingsRegistry::class)]
        private readonly \Closure $settings,
        private readonly ModuleContributionCatalog $contributions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32) has set _route.
        return [KernelEvents::REQUEST => [['redirectModuleIndex', 16]]];
    }

    /**
     * The module route homepage.mode points at, or null when the portal is the homepage.
     */
    public function homepageRoute(): ?string
    {
        try {
            return $this->contributions->homepageModeRoute((string) ($this->settings)()->get('homepage.mode'));
        } catch (\Throwable) {
            // No database yet (install, cache warmup): generate URLs as declared.
            return null;
        }
    }

    public function redirectModuleIndex(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || $route !== $this->homepageRoute()) {
            return;
        }

        $params = (array) $request->attributes->get('_route_params', []) + $request->query->all();
        $event->setResponse(new RedirectResponse($this->generate($route, $params), 301));
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        if ($name !== self::HOME_ROUTE && $name === $this->homepageRoute()) {
            $name = self::HOME_ROUTE;
        }

        return $this->inner->generate($name, $parameters, $referenceType);
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

    public function match(string $pathinfo): array
    {
        return $this->inner->match($pathinfo);
    }

    public function matchRequest(Request $request): array
    {
        return $this->inner->matchRequest($request);
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }
}
