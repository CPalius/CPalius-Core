<?php

declare(strict_types=1);

namespace App\Core\Routing;

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
 * Dinamik URL alias çözümü — SafeModuleRouteLoader'ın (statik, derleme-
 * zamanı modül route'ları) YANINA, ayrı bir mekanizma olarak eklenir.
 *
 * Bilinçli olarak KernelEvents::EXCEPTION dinlenir, KernelEvents::REQUEST
 * DEĞİL: Symfony'nin normal route matcher'ı önce her zaman kendi statik
 * route'larını (Blog'un /blog/{slug} gibi) dener; hiçbiri eşleşmeyip
 * NotFoundHttpException fırlattığında ancak o zaman alias tablosuna
 * bakılır. Bu sayede mevcut modül route'larının önceliği hiç bozulmaz ve
 * route cache invalidation sorunu oluşmaz (Blog PostFrontController'daki
 * generic {slug} route'unun priority: -1 catch-all deseniyle aynı felsefe).
 *
 * Bir alias bulunduğunda hedefin gerçek kanonik URL'ine 301 ile yönlendirilir
 * — alias bir içerik render'ı DEĞİL, kanonik URL'in bir takma adıdır.
 */
final class UrlAliasListener implements EventSubscriberInterface
{
    /**
     * Node'un content type'ına (bkz. Node::$type doc-block'u — modüllerin
     * kendi tiplerini çekirdeği değiştirmeden ekleyebildiği string kolon)
     * karşılık gelen front-end "göster" route adı. Bugün tek somut örnek
     * Blog modülünün 'post' tipi; yeni bir modül kendi tipini eklediğinde
     * bu haritaya kendi route adını eklemesi gerekir (SafeModuleRouteLoader
     * ile aynı "modül kendi front route'unu tanımlar" felsefesi).
     *
     * @var array<string, string>
     */
    private const NODE_TYPE_SHOW_ROUTES = [
        'post' => 'blog_show',
    ];

    public function __construct(
        private readonly UrlAliasRepository $urlAliasRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly RouterInterface $router,
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

        $routeName = self::NODE_TYPE_SHOW_ROUTES[$node->getType()] ?? null;
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

        return $this->tryGenerateRoute('blog_category', ['slug' => $category->getSlug(), '_locale' => $category->getLocale()]);
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
