<?php

declare(strict_types=1);

namespace Modules\Showcase\Twig;

use App\Core\Localization\LocaleProvider;
use Modules\Showcase\Admin\ShowcaseDesk;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcaseLinkService;
use Modules\Showcase\Service\ShowcasePresenter;
use Modules\Showcase\Service\ShowcaseTemplateResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Implementation behind the cp_showcase_* Twig helpers.
 *
 * These are the module's public surface for OTHER modules and for themes: a blog
 * template can drop a product card next to a post, and a forum signature partial
 * can show the author's listings, without either of them importing a single
 * Showcase class. Everything returns null or an empty list rather than throwing,
 * because a helper failing must not take a content page down with it.
 */
final class ShowcaseRuntime implements RuntimeExtensionInterface
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcasePresenter $presenter,
        private readonly ShowcaseLinkService $links,
        private readonly ShowcaseTemplateResolver $templates,
        private readonly LocaleProvider $localeProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array{featured?: bool, type?: string, locale?: string} $options
     *
     * @return list<ShowcaseItem>
     */
    public function items(int $limit = 6, array $options = []): array
    {
        $locale = $this->resolveLocale($options['locale'] ?? null);
        $type = isset($options['type']) && \is_string($options['type'])
            ? $this->types->findOneByMachineName($options['type'])
            : null;

        $items = $this->items->findVisible(
            $locale,
            min(self::MAX_LIMIT, max(1, $limit)),
            (bool) ($options['featured'] ?? false),
            $type,
        );

        // A theme will loop over these and render a card for each; batching the
        // covers here keeps that loop from becoming an N+1 in someone else's
        // template, where it would be much harder to trace back to this module.
        $this->presenter->preload($items);

        return $items;
    }

    public function item(?int $id): ?ShowcaseItem
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        $item = $this->items->find($id);

        // Only visible entries are exposed to templates: a draft embedded in a
        // blog post would leak content the owner has not published.
        return $item instanceof ShowcaseItem && $item->isVisible() ? $item : null;
    }

    public function price(?ShowcaseItem $item): ?string
    {
        return $item instanceof ShowcaseItem ? $this->presenter->price($item) : null;
    }

    public function cover(?ShowcaseItem $item): ?int
    {
        return $item instanceof ShowcaseItem ? $this->presenter->coverAssetId($item) : null;
    }

    public function excerpt(?ShowcaseItem $item, int $length = 160): string
    {
        return $item instanceof ShowcaseItem ? $this->presenter->excerpt($item, $length) : '';
    }

    /**
     * Canonical URL, or '' when the route is unavailable — a template can then
     * render the card without a link instead of erroring.
     */
    public function url(?ShowcaseItem $item): string
    {
        if (!$item instanceof ShowcaseItem) {
            return '';
        }

        try {
            return $this->urlGenerator->generate('showcase_show', [
                '_locale' => $item->getLocale(),
                'slug' => $item->getSlug(),
            ]);
        } catch (RoutingException) {
            return '';
        }
    }

    /**
     * @return list<array{id: int, url: string, label: ?string, internal: bool, icon: string, kindLabel: ?string, host: ?string}>
     */
    public function links(?ShowcaseItem $item): array
    {
        return $item instanceof ShowcaseItem
            ? $this->links->resolveAll($item, $this->resolveLocale(null))
            : [];
    }

    /**
     * @return list<ShowcaseType>
     */
    public function types(): array
    {
        return $this->types->findEnabled();
    }

    public function template(string $name): string
    {
        return $this->templates->resolve($name);
    }

    /**
     * Desk tabs for the admin shell, with the current one marked and the ones
     * the viewer cannot reach removed — a tab that 403s is worse than no tab.
     *
     * @return list<array{id: string, label: string, icon: string, route: string, active: bool}>
     */
    public function deskTabs(): array
    {
        $route = $this->requestStack->getCurrentRequest()?->attributes->get('_route');
        $active = ShowcaseDesk::tabForRoute(\is_string($route) ? $route : '');

        $tabs = [];

        foreach (ShowcaseDesk::tabs() as $tab) {
            if (!$this->security->isGranted($tab['capability'])) {
                continue;
            }

            unset($tab['capability']);
            $tab['active'] = $tab['id'] === $active;
            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * Renders the card partial, theme override included. A failing card returns
     * an empty string: a broken embed must not blank the host page.
     */
    public function card(?ShowcaseItem $item, string $layout = 'grid'): string
    {
        if (!$item instanceof ShowcaseItem) {
            return '';
        }

        try {
            return $this->twig->render($this->templates->resolve('partials/card'), [
                'item' => $item,
                'layout' => $layout === 'list' ? 'list' : 'grid',
                'showcaseUrl' => $this->url($item),
                'showcasePrice' => $this->presenter->price($item),
                'showcaseCover' => $this->presenter->coverAssetId($item),
                'showcaseExcerpt' => $this->presenter->excerpt($item, 120),
            ]);
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveLocale(?string $locale): string
    {
        if ($locale !== null && $this->localeProvider->isSupported($locale)) {
            return $locale;
        }

        $request = $this->requestStack->getCurrentRequest();

        return $this->localeProvider->resolve($request?->getLocale());
    }
}
