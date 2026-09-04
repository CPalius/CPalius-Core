<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Localization\LocaleProvider;
use App\Core\Portal\PortalBlockDataProviderInterface;
use App\Core\Portal\PortalLayoutService;
use App\Core\Portal\WhitepaperContent;
use App\Core\Settings\SettingsRegistry;
use App\Repository\NodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    private const NODE_TYPE_POST = 'post';

    /**
     * @param iterable<PortalBlockDataProviderInterface> $portalBlockProviders
     */
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly RouterInterface $router,
        private readonly PortalLayoutService $portalLayoutService,
        private readonly NodeRepository $nodeRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly WhitepaperContent $whitepaperContent,
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

        return $this->redirectToRoute('theme_cpalius_website_home', ['_locale' => $locale]);
    }

    #[Route(
        '/{_locale}',
        name: 'theme_cpalius_website_home',
        requirements: ['_locale' => '%cpalius.locales_pattern%'],
        methods: ['GET'],
    )]
    public function home(Request $request): Response
    {
        $mode = $this->settingsRegistry->get('homepage.mode');

        return match ($mode) {
            'forum' => $this->redirectToModuleHomeOrPortal('forum_index', $request),
            'blog' => $this->redirectToModuleHomeOrPortal('blog_index', $request),
            default => $this->renderPortal($request),
        };
    }

    /**
     * Public technical whitepaper page rendered by the active theme.
     * Structured per-locale body comes from WhitepaperContent.
     */
    #[Route(
        '/{_locale}/whitepaper',
        name: 'theme_whitepaper',
        requirements: ['_locale' => '%cpalius.locales_pattern%'],
        methods: ['GET'],
    )]
    public function whitepaper(Request $request): Response
    {
        return $this->render('@Theme/whitepaper.html.twig', [
            'doc' => $this->whitepaperContent->forLocale($request->getLocale()),
        ]);
    }

    private function redirectToModuleHomeOrPortal(string $routeName, Request $request): Response
    {
        if ($this->router->getRouteCollection()->get($routeName) === null) {
            return $this->renderPortal($request);
        }

        return $this->redirectToRoute($routeName);
    }

    private function renderPortal(Request $request): Response
    {
        $blocks = $this->portalLayoutService->getEnabledBlocks();
        $portalData = [];
        $forumAvailable = $this->router->getRouteCollection()->get('forum_index') !== null;
        $blogAvailable = $this->router->getRouteCollection()->get('blog_index') !== null;
        $locale = $request->getLocale();

        $visibleBlocks = [];
        foreach ($blocks as $block) {
            $id = (string) ($block['id'] ?? '');
            $limit = (int) ($block['limit'] ?? 5);

            if (str_starts_with($id, 'forum_') && !$forumAvailable) {
                continue;
            }

            if (in_array($id, ['latest_forum_topics', 'popular_forum_topics', 'latest_forum_posts'], true)
                && !$forumAvailable
            ) {
                continue;
            }

            if ($id === 'latest_blog_posts' && !$blogAvailable) {
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

            if ($id === 'latest_blog_posts') {
                $items = $this->nodeRepository
                    ->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE_POST, $locale)
                    ->setMaxResults($limit)
                    ->getQuery()
                    ->getResult();
                if ($items === []) {
                    continue;
                }
                $portalData[$id] = ['items' => $items];
                $visibleBlocks[] = $block;
                continue;
            }

            // Showcase / marketing blocks are Twig + portal.{locale}.yaml — no module data.
            $visibleBlocks[] = $block;
        }

        return $this->render('@Theme/landing.html.twig', [
            'portalBlocks' => $visibleBlocks,
            'portalData' => $portalData,
            'forumAvailable' => $forumAvailable,
            'blogAvailable' => $blogAvailable,
        ]);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null|false false = no provider; null = hide; array = data
     */
    private function provideFromModules(string $blockId, array $block, string $locale): array|null|false
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
