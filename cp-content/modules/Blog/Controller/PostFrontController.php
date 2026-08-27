<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use App\Core\Pagination\Paginator;
use App\Entity\Category;
use App\Entity\Node;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use Modules\Blog\Service\BlogAppearanceService;
use Modules\Blog\Service\BlogPostPresentationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Blog'un gerçek ön yüz (frontend) render'ı — cpalius-website temasının
 * layout.html.twig sarmalayıcısını (header/footer) kullanır (bkz. Manifesto
 * Law 7, tema izolasyonu). Node::type = 'post' olan içerikleri listeler/
 * gösterir; Manifesto Law 3.1 gereği içerik hâlâ tek bir Node tablosunda
 * yaşar, bu controller sadece "post" tipine bir bakış açısı sunar.
 *
 * Route path'leri BİLİNÇLİ OLARAK "/{_locale}" prefix'i İÇERMEZ: bu prefix
 * zaten routes.yaml'daki blog_module_front yükleyicisi tarafından (Admin/
 * hariç tüm Controller/ dizinine) uygulanıyor (bkz. Resources/config/
 * routes.yaml) — burada tekrar eklemek "değişken adı _locale birden fazla
 * kez referans edilemez" derleme hatasına yol açar.
 *
 * Tüm listeleme action'ları (index/category/tag/search) App\Core\Pagination
 * \Paginator üzerinden ?page= ile sayfalanır — KnpPaginatorBundle KURULU
 * DEĞİL, bu yüzden doctrine/orm'un dahili Paginator'ını saran hafif
 * servis kullanılır (bkz. Paginator sınıfının kendi docblock'u).
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
    ) {
    }

    /**
     * Blog ana listesi — archive.html.twig, tüm yayınlanmış yazıların
     * kronolojik (en yeni önce) tam arşividir.
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

        return $this->render('@CpaliusWebsiteTheme/blog/archive.html.twig', [
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
        if (!$category instanceof Category) {
            throw new NotFoundHttpException($this->translator->trans('blog.front.error.category_not_found'));
        }

        $qb = $this->nodeRepository->createPublishedByCategoryQueryBuilder($category->getId(), self::NODE_TYPE, $locale);
        $result = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $this->appearanceService->postsPerPage(),
        );

        return $this->render('@CpaliusWebsiteTheme/blog/archive.html.twig', [
            'posts' => $result,
            'category' => $category,
            'heading' => $category->getName(),
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    /**
     * Öncelik negatif verilir: /blog/kategori/{slug} ve /blog/ara gibi
     * daha spesifik statik segmentli route'lar bu genel {slug} kalıbıyla
     * ÇAKIŞMASIN diye önce onlar denenir (bkz. show() ile aynı gerekçe).
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

        return $this->render('@CpaliusWebsiteTheme/blog/tag/show.html.twig', [
            'posts' => $result,
            'tagSlug' => $slug,
            'heading' => '#'.$slug,
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    /**
     * Serbest metin arama — Node::title (sabit SQL kolonu) üzerinden
     * çalışır (bkz. NodeRepository::createSearchQueryBuilder docblock'u:
     * JSON içi excerpt/body alanları bilinçli olarak taranmaz, performans
     * bütçesi gereği). Boş arama terimi boş sonuç kümesi döner — sıfır
     * koşullu bir sorgu "tüm yazıları getir" anlamına gelip kullanıcıyı
     * yanıltmamalı.
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

        return $this->render('@CpaliusWebsiteTheme/blog/search.html.twig', [
            'posts' => $result,
            'term' => $term,
            'heading' => $term !== ''
                ? $this->translator->trans('blog.search.title_query', ['term' => $term])
                : $this->translator->trans('blog.search.title_default'),
            ...$this->appearanceViewData($locale, showFeatured: false),
        ]);
    }

    /**
     * Modules\Blog\Plugin\BlogArchivePlugin'in ürettiği yıl/ay linklerinin
     * hedefi — sidebar'daki arşiv widget'ı salt dekoratif kalmasın diye
     * gerçek bir filtrelenmiş liste sayfası açar. archive.html.twig'i
     * (Faz 1/2'den beri var olan aynı şablon) sadece heading ve veri
     * kaynağı farklı olacak şekilde yeniden kullanır.
     *
     * Öncelik diğer statik segmentli route'larla (blog_category, blog_tag,
     * blog_search) aynı gerekçeyle 1 verilir: /blog/{slug} (priority: -1)
     * bu kalıpla asla çakışmaz çünkü {slug} tek segmenttir, ama tutarlılık
     * için aynı öncelik deseni korunur.
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

        return $this->render('@CpaliusWebsiteTheme/blog/archive.html.twig', [
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
     * Öncelik negatif verilir: /blog/kategori/{slug}, /blog/etiket/{slug}
     * ve /blog/ara route'ları bu genel {slug} kalıbıyla ÇAKIŞMASIN diye
     * önce onlar denenir.
     */
    #[Route('/blog/{slug}', name: 'blog_show', priority: -1)]
    public function show(Request $request, string $slug): Response
    {
        $node = $this->nodeRepository->findOnePublishedBySlugAndLocale($slug, $request->getLocale());
        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE) {
            throw new NotFoundHttpException($this->translator->trans('blog.posts.error.not_found'));
        }

        return $this->render('@CpaliusWebsiteTheme/blog/show.html.twig', [
            'post' => $node,
            'relatedPosts' => $this->nodeRepository->findRelatedPosts($node),
            'featuredImageUrl' => $this->presentationService->resolveFeaturedImageUrl($node),
        ]);
    }

    /**
     * @return array{
     *     blogHero: array<string, mixed>,
     *     featuredPosts: list<Node>,
     *     blogCategories: array{roots: list<\App\Entity\Category>, postCounts: array<int, int>},
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
