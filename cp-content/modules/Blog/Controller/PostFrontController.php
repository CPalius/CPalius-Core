<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use App\Core\OriginCache\CacheTagCollector;
use App\Core\Pagination\PaginatedResult;
use App\Core\Pagination\Paginator;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Modules\Blog\Service\BlogAppearanceService;
use Modules\Blog\Service\BlogCommentService;
use Modules\Blog\Service\BlogPostPresentationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly TagRepository $tagRepository,
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

    /**
     * Missing locale sibling behaves like blog_show: redirect to the translated
     * category when there is one, otherwise a theme page with a 404 status.
     *
     * The hard throw this replaces was the second-largest source of rows in the
     * production error log. A category URL is shared and indexed without its
     * locale surviving the trip — a Turkish slug arriving on the English site is
     * ordinary traffic, not an application failure, and answering it with an
     * uncaught exception both logged it as one and showed the visitor the
     * framework error page instead of the site.
     */
    #[Route('/blog/kategori/{slug}', name: 'blog_category')]
    public function category(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();

        $category = $this->categoryRepository->findOneBySlug($slug, $locale);
        if (!$category instanceof Term) {
            return $this->resolveCrossLocaleCategory($slug, $locale);
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
        $tagName = $this->tagRepository->findOneBySlug($slug, $locale)?->getName() ?? $slug;

        return $this->render('@Theme/blog/tag/show.html.twig', [
            'posts' => $result,
            'tagSlug' => $slug,
            'tagName' => $tagName,
            'heading' => '#'.$tagName,
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
        $preview = false;

        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE) {
            // A draft or scheduled post is visible to the people who may edit it (author, or an admin),
            // so a writer can read their own work on the real page before publishing it.
            $node = $this->findPreviewablePost($slug, $locale);
            if (!$node instanceof Node) {
                return $this->resolveCrossLocalePost($request, $slug, $locale);
            }
            $preview = true;
        }

        // LocaleSwitchService reads TranslatableInterface from request attributes.
        $request->attributes->set('blog_post', $node);

        $user = $this->getUser();

        $this->tagCollector->addEntityTag('node', (int) $node->getId());

        $response = $this->render('@Theme/blog/show.html.twig', [
            'post' => $node,
            'isPreview' => $preview,
            'authorTools' => $this->authorTools($node),
            'related' => $this->appearanceService->resolveRelatedPosts($node),
            'featuredImageUrl' => $this->presentationService->resolveFeaturedImageUrl($node),
            'commentContext' => $this->commentService->buildShowContext(
                $node,
                $request,
                $user instanceof User ? $user : null,
            ),
        ]);

        if ($preview) {
            // Never indexed, never stored by a shared cache.
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }

    /**
     * Whether the current user may edit this post: any post with ".any", their own with ".own".
     * Same rule as the Studio edit screen (PostAdminController::assertOwnOrAny).
     */
    private function canEditPost(Node $node): bool
    {
        return $this->isGranted('node.post.edit.any') || $this->isGranted('node.post.edit.own', $node);
    }

    /**
     * An unpublished, not-deleted post of this slug and language, only for someone allowed to edit it.
     */
    private function findPreviewablePost(string $slug, string $locale): ?Node
    {
        if (!$this->getUser() instanceof User) {
            return null;
        }

        $node = $this->nodeRepository->findOneBy(['slug' => $slug, 'locale' => $locale, 'type' => self::NODE_TYPE]);

        return $node instanceof Node
            && $node->getDeletedAt() === null
            && $node->getStatus() !== Node::STATUS_PUBLISHED
            && $this->canEditPost($node)
                ? $node
                : null;
    }

    /**
     * The writer's toolbar above a post: edit, publish (while it is not live), back to the list.
     * null for everyone else, so the theme prints nothing and a visitor never learns it exists.
     *
     * @return array<string, mixed>|null
     */
    private function authorTools(Node $node): ?array
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->canEditPost($node)) {
            return null;
        }

        return [
            'editUrl' => $this->generateUrl('admin_posts_edit', ['id' => $node->getId()]),
            'listUrl' => $this->generateUrl('admin_posts_index', ['locale' => $node->getLocale()]),
            'canPublish' => $node->getStatus() !== Node::STATUS_PUBLISHED && $this->isGranted('node.post.publish'),
            'publishUrl' => $this->generateUrl('admin_posts_publish', ['id' => $node->getId()]),
            'status' => $node->getStatus(),
            'isOwner' => $node->getAuthor()?->getId() === $user->getId(),
            // Quick-edit panel (see author_toolbar.html.twig): state to prefill, endpoints to call.
            'quickUrl' => $this->generateUrl('admin_posts_quick', ['id' => $node->getId()]),
            'canSchedule' => $this->isGranted('node.post.publish'),
            'canUpload' => $this->isGranted('media.upload'),
            'uploadUrl' => $this->isGranted('media.upload') ? $this->generateUrl('admin_media_upload') : null,
            'commentsEnabled' => !\in_array($node->getDataValue('comments_enabled', 1), [0, '0', false, null, ''], true),
            'featured' => (bool) $node->getDataValue('is_featured', false),
            'hasCover' => is_numeric($node->getDataValue('featured_image_asset_id')),
            'dateValue' => ($node->getPublishedAt() ?? (\is_string($node->getDataValue('scheduled_for')) ? new \DateTimeImmutable((string) $node->getDataValue('scheduled_for')) : null))?->format('Y-m-d\TH:i') ?? '',
        ];
    }

    /**
     * Same category slug in another locale → redirect to the sibling, or a soft
     * 404 page listing no posts.
     */
    private function resolveCrossLocaleCategory(string $slug, string $locale): Response
    {
        $source = $this->categoryRepository->findOneBySlugAnyLocale($slug);

        if ($source instanceof Term) {
            $translation = $this->categoryRepository->findTranslation($source, $locale);

            if ($translation instanceof Term) {
                return $this->redirectToRoute('blog_category', [
                    '_locale' => $locale,
                    'slug' => $translation->getSlug(),
                ]);
            }
        }

        // No sibling: the archive shell with an empty list says "this category
        // has nothing here in your language" far better than an error page,
        // and the 404 status keeps search engines from indexing it.
        //
        // An empty PaginatedResult rather than null, so any theme's archive
        // template keeps working — `posts.isEmpty` on a null is a Twig error,
        // and a theme is the one file a site owner is expected to have replaced.
        return $this->render(
            '@Theme/blog/archive.html.twig',
            [
                'posts' => new PaginatedResult([], 0, 1, $this->appearanceService->postsPerPage()),
                'category' => null,
                'heading' => $this->translator->trans('blog.front.error.category_not_found'),
                ...$this->appearanceViewData($locale, showFeatured: false),
            ],
            new Response('', Response::HTTP_NOT_FOUND),
        );
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
