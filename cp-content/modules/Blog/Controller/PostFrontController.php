<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use App\Core\OriginCache\CacheTagCollector;
use App\Core\Pagination\Paginator;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use Modules\Blog\Service\BlogAppearanceService;
use Modules\Blog\Service\BlogCommentService;
use Modules\Blog\Service\BlogPostPresentationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Front blog render using the theme layout. Lists/shows Node type "post" (Law 3.1/7).
 * Locale prefix comes from routes.yaml only; listing uses App\Core\Pagination\Paginator.
 */
final class PostFrontController extends AbstractController
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly Paginator $paginator,
        private readonly BlogPostPresentationService $presentationService,
        private readonly BlogAppearanceService $appearanceService,
        private readonly TranslatorInterface $translator,
        private readonly BlogCommentService $commentService,
        private readonly CacheTagCollector $tagCollector,
    ) {
    }

    /**
     * Full chronological archive of published posts (newest first).
     */
    #[Route('/blog', name: 'blog_index')]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();

        $qb = $this->nodeRepository->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE, $locale);
        $result = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $this->appearanceService->postsPerPage(),
        );

        $this->tagCollector->addListTag('node', self::NODE_TYPE);

        return $this->render('@Theme/blog/archive.html.twig', [
            'posts' => $result,
            'category' => null,
            'heading' => $this->translator->trans('blog.front.archive.all_posts_heading'),
            ...$this->appearanceViewData($locale, showFeatured: true),
        ]);
    }

    #[Route('/blog/kategori/{slug}', name: 'blog_category')]
    public function category(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();

        $category = $this->categoryRepository->findOneBySlug($slug, $locale);
        if (!$category instanceof Term) {
            throw new NotFoundHttpException($this->translator->trans('blog.front.error.category_not_found'));
        }

        $qb = $this->nodeRepository->createPublishedByCategoryQueryBuilder($category->getId(), self::NODE_TYPE, $locale);
        $result = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $this->appearanceService->postsPerPage(),
        );

        $this->tagCollector->addListTag('node', self::NODE_TYPE);
        $this->tagCollector->addEntityTag('category', (int) $category->getId());

        return $this->render('@Theme/blog/archive.html.twig', [
            'posts' => $result,
            'category' => $category,
            'heading' => $category->getName(),
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    /**
     * priority 1 so static segments like /blog/kategori/{slug} win over /blog/{slug}.
     */
    #[Route('/blog/etiket/{slug}', name: 'blog_tag', priority: 1)]
    public function tag(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();

        $qb = $this->nodeRepository->createPublishedByTagQueryBuilder($slug, self::NODE_TYPE, $locale);
        $result = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $this->appearanceService->postsPerPage(),
        );

        $this->tagCollector->addListTag('node', self::NODE_TYPE);

        return $this->render('@Theme/blog/tag/show.html.twig', [
            'posts' => $result,
            'tagSlug' => $slug,
            'heading' => '#'.$slug,
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    /**
     * Title-only search; empty term returns no rows (does not list all posts).
     */
    #[Route('/blog/ara', name: 'blog_search')]
    public function search(Request $request): Response
    {
        $locale = $request->getLocale();
        $term = trim((string) $request->query->get('q', ''));

        $result = $term !== ''
            ? $this->paginator->paginate(
                $this->nodeRepository->createSearchQueryBuilder($term, self::NODE_TYPE, $locale),
                $request->query->getInt('page', 1),
                $this->appearanceService->postsPerPage(),
            )
            : null;

        return $this->render('@Theme/blog/search.html.twig', [
            'posts' => $result,
            'term' => $term,
            'heading' => $term !== ''
                ? $this->translator->trans('blog.search.title_query', ['term' => $term])
                : $this->translator->trans('blog.search.title_default'),
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    /**
     * Month archive page for BlogArchivePlugin links; reuses archive.html.twig.
     */
    #[Route('/blog/arsiv/{year}/{month}', name: 'blog_archive_month', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], priority: 1)]
    public function archiveMonth(Request $request, int $year, int $month): Response
    {
        $locale = $request->getLocale();

        $qb = $this->nodeRepository->createPublishedByDateRangeQueryBuilder(self::NODE_TYPE, $locale, $year, $month);
        $result = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $this->appearanceService->postsPerPage(),
        );

        $this->tagCollector->addListTag('node', self::NODE_TYPE);

        return $this->render('@Theme/blog/archive.html.twig', [
            'posts' => $result,
            'category' => null,
            'heading' => $this->translator->trans('blog.front.archive.month_heading', [
                'month' => $this->monthName($month),
                'year' => $year,
            ]),
            'paginationRoute' => 'blog_archive_month',
            'paginationRouteParams' => ['year' => $year, 'month' => $month],
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    private function monthName(int $month): string
    {
        return match ($month) {
            1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 => $this->translator->trans('blog.front.month.'.$month),
            default => (string) $month,
        };
    }

    /**
     * Negative priority so static blog_* routes win over this catch-all slug.
     * Missing locale sibling: redirect to the published translation when present,
     * otherwise a theme page (never the Symfony exception screen).
     */
    #[Route('/blog/{slug}', name: 'blog_show', priority: -1)]
    public function show(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();
        $node = $this->nodeRepository->findOnePublishedBySlugAndLocale($slug, $locale);

        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE) {
            return $this->resolveCrossLocalePost($request, $slug, $locale);
        }

        // LocaleSwitchService reads TranslatableInterface from request attributes.
        $request->attributes->set('blog_post', $node);

        $user = $this->getUser();

        $this->tagCollector->addEntityTag('node', (int) $node->getId());

        return $this->render('@Theme/blog/show.html.twig', [
            'post' => $node,
            'relatedPosts' => $this->nodeRepository->findRelatedPosts($node),
            'featuredImageUrl' => $this->presentationService->resolveFeaturedImageUrl($node),
            'commentContext' => $this->commentService->buildShowContext(
                $node,
                $request,
                $user instanceof User ? $user : null,
            ),
        ]);
    }

    /**
     * Same slug in another locale → redirect to published sibling, or soft unavailable page.
     */
    private function resolveCrossLocalePost(Request $request, string $slug, string $locale): Response
    {
        $source = $this->nodeRepository->findOnePublishedBySlug($slug, self::NODE_TYPE);
        if (!$source instanceof Node) {
            return $this->renderBlogUnavailable(
                source: null,
                requestedSlug: $slug,
                locale: $locale,
            );
        }

        $groupId = $source->getTranslationGroupId();
        if ($groupId !== null) {
            $translation = $this->nodeRepository->findTranslation($groupId, $locale);
            if (
                $translation instanceof Node
                && $translation->getType() === self::NODE_TYPE
                && $translation->getStatus() === Node::STATUS_PUBLISHED
                && $translation->getDeletedAt() === null
            ) {
                return $this->redirectToRoute('blog_show', [
                    '_locale' => $locale,
                    'slug' => $translation->getSlug(),
                ]);
            }
        }

        return $this->renderBlogUnavailable(
            source: $source,
            requestedSlug: $slug,
            locale: $locale,
        );
    }

    private function renderBlogUnavailable(?Node $source, string $requestedSlug, string $locale): Response
    {
        return $this->render(
            '@Theme/blog/unavailable.html.twig',
            [
                'sourcePost' => $source,
                'requestedSlug' => $requestedSlug,
                'locale' => $locale,
                'heading' => $source instanceof Node
                    ? $this->translator->trans('blog.front.unavailable.translation_heading')
                    : $this->translator->trans('blog.front.unavailable.not_found_heading'),
            ],
            new Response('', Response::HTTP_NOT_FOUND),
        );
    }

    /**
     * @return array{
     *     blogHero: array<string, mixed>,
     *     featuredPosts: list<Node>,
     *     blogCategories: array{roots: list<Term>, postCounts: array<int, int>},
     *     blogStats: array{posts: int, categories: int, tags: int},
     *     listLayout: string
     * }
     */
    private function appearanceViewData(string $locale, bool $showFeatured): array
    {
        $hero = $this->appearanceService->resolveHero();

        return [
            'blogHero' => $hero,
            'featuredPosts' => $showFeatured ? $this->appearanceService->resolveFeaturedPosts($locale) : [],
            'blogCategories' => $showFeatured
                ? $this->appearanceService->resolveCategoryTree($locale)
                : ['roots' => [], 'postCounts' => []],
            'blogStats' => $hero['showStats'] ? $this->appearanceService->resolveStats($locale) : ['posts' => 0, 'categories' => 0, 'tags' => 0],
            'listLayout' => $this->appearanceService->listLayout(),
        ];
    }
}
