<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Portal\PortalLayoutService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\ForumSection;
use App\Repository\ForumPostRepository;
use App\Repository\ForumSectionRepository;
use App\Repository\ForumTopicRepository;
use App\Repository\NodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Aktif tema için ana sayfa render'ı — portal blok layout'u Studio'dan yönetilir.
 */
final class ThemeController extends AbstractController
{
    private const NODE_TYPE_POST = 'post';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly RouterInterface $router,
        private readonly PortalLayoutService $portalLayoutService,
        private readonly ForumTopicRepository $forumTopicRepository,
        private readonly ForumPostRepository $forumPostRepository,
        private readonly ForumSectionRepository $forumSectionRepository,
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    #[Route('/', name: 'theme_cpalius_website_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $mode = $this->settingsRegistry->get('homepage.mode');

        return match ($mode) {
            'forum' => $this->redirectToModuleHomeOrPortal('forum_index', $request),
            'blog' => $this->redirectToModuleHomeOrPortal('blog_index', $request),
            default => $this->renderPortal($request),
        };
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

            if (in_array($id, ['latest_forum_topics', 'popular_forum_topics', 'latest_forum_posts', 'forum_boards', 'forum_stats'], true)
                && !$forumAvailable
            ) {
                continue;
            }

            if ($id === 'latest_blog_posts' && !$blogAvailable) {
                continue;
            }

            switch ($id) {
                case 'latest_forum_topics':
                    $items = $this->forumTopicRepository->findLatest($limit);
                    if ($items === []) {
                        continue 2;
                    }
                    $portalData[$id] = ['items' => $items];
                    break;

                case 'popular_forum_topics':
                    $items = $this->forumTopicRepository->findPopular($limit);
                    if ($items === []) {
                        continue 2;
                    }
                    $portalData[$id] = ['items' => $items];
                    break;

                case 'latest_forum_posts':
                    $items = $this->forumPostRepository->findLatest($limit);
                    if ($items === []) {
                        continue 2;
                    }
                    $portalData[$id] = ['items' => $items];
                    break;

                case 'latest_blog_posts':
                    $items = $this->nodeRepository
                        ->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE_POST, $locale)
                        ->setMaxResults($limit)
                        ->getQuery()
                        ->getResult();
                    if ($items === []) {
                        continue 2;
                    }
                    $portalData[$id] = ['items' => $items];
                    break;

                case 'forum_boards':
                    $boards = $this->loadForumBoards($locale, $limit);
                    if ($boards === []) {
                        continue 2;
                    }
                    $portalData[$id] = ['items' => $boards];
                    break;

                case 'forum_stats':
                    $portalData[$id] = $this->aggregateForumStats($locale);
                    break;
            }

            $visibleBlocks[] = $block;
        }

        return $this->render('@CpaliusWebsiteTheme/landing.html.twig', [
            'portalBlocks' => $visibleBlocks,
            'portalData' => $portalData,
        ]);
    }

    /**
     * @return list<ForumSection>
     */
    private function loadForumBoards(string $locale, int $limit): array
    {
        $sections = $this->forumSectionRepository->findAllByLocale($locale);
        $boards = array_values(array_filter(
            $sections,
            static fn (ForumSection $s): bool => $s->allowsTopics(),
        ));

        usort(
            $boards,
            static fn (ForumSection $a, ForumSection $b): int => $b->getPostCount() <=> $a->getPostCount()
                ?: $a->getSortOrder() <=> $b->getSortOrder(),
        );

        return array_slice($boards, 0, $limit);
    }

    /** @return array{topics: int, posts: int, sections: int} */
    private function aggregateForumStats(string $locale): array
    {
        $sections = $this->forumSectionRepository->findAllByLocale($locale);
        $topics = 0;
        $posts = 0;
        $boardCount = 0;

        foreach ($sections as $section) {
            $topics += $section->getTopicCount();
            $posts += $section->getPostCount();
            if ($section->allowsTopics()) {
                ++$boardCount;
            }
        }

        return [
            'topics' => $topics,
            'posts' => $posts,
            'sections' => $boardCount,
        ];
    }
}
