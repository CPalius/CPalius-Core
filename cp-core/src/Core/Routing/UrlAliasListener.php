<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Module\ModuleContributionCatalog;
use App\Entity\Node;
use App\Entity\UrlAlias;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\UrlAliasRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Dynamic URL alias resolution alongside SafeModuleRouteLoader static module routes.
 * Listens on KernelEvents::EXCEPTION (not REQUEST) so static routes win; unresolved 404s check the alias table and 301 to canonical URLs.
 */
final class UrlAliasListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlAliasRepository $urlAliasRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly RouterInterface $router,
        private readonly ModuleContributionCatalog $contributions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [['onKernelException', 0]],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $request = $event->getRequest();
        $aliasPath = ltrim($request->getPathInfo(), '/');

        if ($aliasPath === '') {
            return;
        }

        $alias = $this->urlAliasRepository->findOneActiveByPathAndLocale($aliasPath, $request->getLocale());
        if (!$alias instanceof UrlAlias) {
            return;
        }

        $targetUrl = $this->resolveTargetUrl($alias);
        if ($targetUrl === null) {
            return;
        }

        $event->setResponse(new RedirectResponse($targetUrl, 301));
    }

    private function resolveTargetUrl(UrlAlias $alias): ?string
    {
        return match ($alias->getTargetType()) {
            UrlAlias::TARGET_NODE => $this->resolveNodeUrl($alias),
            UrlAlias::TARGET_CATEGORY => $this->resolveCategoryUrl($alias),
            UrlAlias::TARGET_ROUTE => $this->resolveRouteUrl($alias),
            default => null,
        };
    }

    private function resolveNodeUrl(UrlAlias $alias): ?string
    {
        if ($alias->getTargetNodeId() === null) {
            return null;
        }

        $node = $this->nodeRepository->find($alias->getTargetNodeId());
        if (!$node instanceof Node || $node->getStatus() !== Node::STATUS_PUBLISHED) {
            return null;
        }

        $routeName = $this->contributions->nodeShowRoute($node->getType());
        if ($routeName === null) {
            return null;
        }

        return $this->tryGenerateRoute($routeName, ['slug' => $node->getSlug(), '_locale' => $node->getLocale()]);
    }

    private function resolveCategoryUrl(UrlAlias $alias): ?string
    {
        if ($alias->getTargetCategoryId() === null) {
            return null;
        }

        $category = $this->categoryRepository->find($alias->getTargetCategoryId());
        if ($category === null) {
            return null;
        }

        $routeName = $this->contributions->categoryShowRoute();
        if ($routeName === null) {
            return null;
        }

        return $this->tryGenerateRoute($routeName, ['slug' => $category->getSlug(), '_locale' => $category->getLocale()]);
    }

    private function resolveRouteUrl(UrlAlias $alias): ?string
    {
        if ($alias->getTargetRouteName() === null) {
            return null;
        }

        return $this->tryGenerateRoute($alias->getTargetRouteName(), []);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function tryGenerateRoute(string $routeName, array $parameters): ?string
    {
        try {
            return $this->router->generate($routeName, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
