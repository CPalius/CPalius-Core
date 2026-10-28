<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Homepage\HomepageHtmlPage;
use App\Core\Homepage\HomepageStore;
use App\Core\Localization\LocaleProvider;
use App\Core\Module\ModuleContributionCatalog;
use App\Core\Portal\HomepageSimpleContent;
use App\Core\Portal\PortalBlockDataProviderInterface;
use App\Core\Portal\PortalLayoutService;
use App\Core\Settings\SettingsRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Active-theme homepage render; portal block layout is managed from Studio.
 * Module data comes from PortalBlockDataProviderInterface implementations.
 */
final class ThemeController extends AbstractController
{
    /**
     * @param iterable<PortalBlockDataProviderInterface> $portalBlockProviders
     */
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly RouterInterface $router,
        private readonly PortalLayoutService $portalLayoutService,
        private readonly LocaleProvider $localeProvider,
        private readonly ModuleContributionCatalog $contributions,
        private readonly HomepageSimpleContent $simpleContent,
        private readonly HomepageStore $homepage,
        private readonly HomepageHtmlPage $htmlPage,
        private readonly Security $security,
        #[TaggedIterator('cpalius.portal.block_data_provider')]
        private readonly iterable $portalBlockProviders = [],
    ) {
    }

    /**
     * Root path has no locale prefix; redirect to the locale resolved by LocaleListener.
     */
    #[Route('/', name: 'theme_home_root', methods: ['GET'])]
    public function root(Request $request): RedirectResponse
    {
        $locale = $this->localeProvider->resolve($request->getLocale());

        return $this->redirectToRoute('site_home', ['_locale' => $locale]);
    }

    // 'site_home' is the theme-neutral name links are generated with; the legacy name stays
    // first because it is what the router reports as _route for the home page.
    #[Route(
        '/{_locale}',
        name: 'theme_cpalius_website_home',
        requirements: ['_locale' => '%cpalius.locales_pattern%'],
        methods: ['GET'],
    )]
    #[Route(
        '/{_locale}',
        name: 'site_home',
        requirements: ['_locale' => '%cpalius.locales_pattern%'],
        methods: ['GET'],
    )]
    public function home(Request $request): Response
    {
        $mode = (string) $this->settingsRegistry->get('homepage.mode');
        $route = $this->contributions->homepageModeRoute($mode);
        if ($route !== null) {
            return $this->renderModuleHomeOrPortal($route, $request);
        }

        $preview = $this->enterPreview($request);
        if ($this->homepage->engine() === 'html') {
            return $this->htmlPage->response($request, $preview);
        }

        $response = $this->renderPortal($request, $preview);
        if ($preview) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * The editor's frame asks for ?_hp=draft. Only someone who may change the
     * homepage can see an unpublished one, and nobody else can make a request
     * render anything but the published page.
     */
    private function enterPreview(Request $request): bool
    {
        if ($request->query->get('_hp') !== 'draft' || !$request->hasPreviousSession()) {
            return false;
        }
        $user = $this->security->getUser();
        if ($user === null || !$this->security->isGranted('system.settings.manage')) {
            return false;
        }

        return $this->homepage->usePreview($user->getUserIdentifier());
    }

    /**
     * Served in place, not redirected: the address stays /{_locale}. The sub-request keeps the
     * module's _route so its SEO provider and templates behave as on their own page;
     * HomepageModeRouter points every link to that route back here.
     */
    private function renderModuleHomeOrPortal(string $routeName, Request $request): Response
    {
        $route = $this->router->getRouteCollection()->get($routeName);
        $controller = $route?->getDefault('_controller');
        if (!\is_string($controller) || $controller === '') {
            return $this->renderPortal($request);
        }

        $locale = $request->getLocale();

        return $this->forward($controller, [
            ...$route->getDefaults(),
            '_locale' => $locale,
            '_route' => $routeName,
            '_route_params' => ['_locale' => $locale],
        ], $request->query->all());
    }

    private function renderPortal(Request $request, bool $preview = false): Response
    {
        $draft = $preview ? $this->homepage->draftRaw() : null;
        $template = $this->simpleContent->template();
        if ($draft !== null && \in_array($draft['template'] ?? null, HomepageSimpleContent::TEMPLATES, true)) {
            $template = (string) $draft['template'];
        }
        $catalog = $this->portalLayoutService->getCatalog();
        $availability = $this->moduleAvailability();
        $locale = $request->getLocale();

        $portalData = [];
        $visibleBlocks = [];

        if ($template === 'showcase') {
            $blocks = $this->homepage->draftLayout() !== null
                ? $this->portalLayoutService->enabledFromDraft($this->homepage->draftLayout())
                : $this->portalLayoutService->getEnabledBlocks();
        } else {
            // "simple": the intro alone. "feed": the intro plus every live
            // module feed (latest posts, topics, stats) that is installed.
            $portalData['simple_intro'] = $draft !== null && \is_array($draft['simple'] ?? null)
                ? $this->simpleContent->forLocaleFromDraft($draft['simple'], (string) ($draft['simple_image'] ?? ''), $locale)
                : $this->simpleContent->forLocale($locale);
            $visibleBlocks[] = ['id' => 'simple_intro', 'enabled' => true];
            $blocks = [];
            if ($template === 'feed') {
                foreach ($catalog as $id => $meta) {
                    if (($meta['group'] ?? '') === 'portal') {
                        $blocks[] = ['enabled' => true] + ($meta['default'] ?? []) + ['id' => (string) $id];
                    }
                }
            }
        }

        foreach ($blocks as $block) {
            $id = (string) ($block['id'] ?? '');
            $requiresRoute = $catalog[$id]['requiresRoute'] ?? null;
            if (\is_string($requiresRoute) && $requiresRoute !== ''
                && $this->router->getRouteCollection()->get($requiresRoute) === null
            ) {
                continue;
            }

            $provided = $this->provideFromModules($id, $block, $locale);
            if ($provided !== false) {
                if ($provided === null) {
                    continue;
                }
                $portalData[$id] = $provided;
                $visibleBlocks[] = $block;
                continue;
            }

            // Showcase / marketing blocks are Twig + portal.{locale}.yaml — no module data.
            $visibleBlocks[] = $block;
        }

        return $this->render('@Theme/landing.html.twig', [
            'portalBlocks' => $visibleBlocks,
            'portalData' => $portalData,
            'portalAvailability' => $availability,
            'forumAvailable' => $availability['forum'] ?? false,
            'blogAvailable' => $availability['blog'] ?? false,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function moduleAvailability(): array
    {
        $available = [];
        foreach ($this->contributions->homepageModes() as $id => $mode) {
            $available[$id] = $this->router->getRouteCollection()->get($mode['route']) !== null;
        }

        return $available;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|false|null false = no provider; null = hide; array = data
     */
    private function provideFromModules(string $blockId, array $block, string $locale): array|false|null
    {
        foreach ($this->portalBlockProviders as $provider) {
            if (!$provider->supports($blockId)) {
                continue;
            }

            return $provider->provide($blockId, $block, $locale);
        }

        return false;
    }
}
