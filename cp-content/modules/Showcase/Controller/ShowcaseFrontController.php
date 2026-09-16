<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller;

use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\CacheTagCollector;
use App\Core\Pagination\Paginator;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Entity\User;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Query\ShowcaseFilter;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseReviewRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcaseAccess;
use Modules\Showcase\Service\ShowcaseConfig;
use Modules\Showcase\Service\ShowcaseItemManager;
use Modules\Showcase\Service\ShowcaseLinkService;
use Modules\Showcase\Service\ShowcaseListingFilterBuilder;
use Modules\Showcase\Service\ShowcaseMediaService;
use Modules\Showcase\Service\ShowcasePresenter;
use Modules\Showcase\Service\ShowcaseReviewService;
use Modules\Showcase\Service\ShowcaseTemplateResolver;
use Modules\Showcase\Service\ShowcaseTypeManager;
use Modules\Showcase\Service\ShowcaseUrlValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public showcase: the grid, the per-type listing with its generated facets, and
 * the detail page.
 *
 * Templates are resolved through ShowcaseTemplateResolver, so the module renders
 * out of the box and a theme can still override any screen by shipping
 * `showcase/<name>.html.twig` (Law 7: the theme owns presentation).
 */
final class ShowcaseFrontController extends AbstractController
{
    private const REVIEW_CSRF = 'showcase_review';

    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseReviewRepository $reviews,
        private readonly ShowcaseListingFilterBuilder $filterBuilder,
        private readonly ShowcaseTemplateResolver $templates,
        private readonly ShowcasePresenter $presenter,
        private readonly ShowcaseMediaService $media,
        private readonly ShowcaseLinkService $links,
        private readonly ShowcaseTypeManager $typeManager,
        private readonly ShowcaseItemManager $itemManager,
        private readonly ShowcaseReviewService $reviewService,
        private readonly ShowcaseAccess $access,
        private readonly ShowcaseConfig $config,
        private readonly ShowcaseUrlValidator $urls,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly TermRepository $terms,
        private readonly LocaleProvider $localeProvider,
        private readonly Paginator $paginator,
        private readonly CacheTagCollector $cacheTags,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/showcase', name: 'showcase_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();
        $withPrice = $this->filterBuilder->supportsPrice(null, $this->types->findEnabled());
        $filter = $this->filterBuilder->fromRequest($request, $locale, null, withPrice: $withPrice);

        $result = $this->paginator->paginate(
            $this->items->createFilteredQueryBuilder($filter),
            $request->query->getInt('page', 1),
            $this->config->itemsPerPage(),
        );

        $this->cacheTags->addListTag(ShowcaseItem::ENTITY_TYPE_ID);

        $featured = $this->items->findVisible($locale, 4, featuredOnly: true);

        // Two queries for the whole page instead of one per card (Law 6.1).
        $this->presenter->preload($result);
        $this->presenter->preload($featured);

        return $this->render($this->templates->resolve('index'), $this->listingViewData($request, $locale, null, $result, $withPrice) + [
            'featured' => $featured,
        ]);
    }

    /**
     * priority 1 so "/showcase/t/..." is matched before the "/showcase/{slug}"
     * catch-all below.
     */
    #[Route('/showcase/t/{machineName}', name: 'showcase_type', methods: ['GET'], priority: 1, requirements: ['machineName' => '[a-z][a-z0-9_]{0,31}'])]
    public function type(string $machineName, Request $request): Response
    {
        $type = $this->types->findOneByMachineName($machineName);

        if (!$type instanceof ShowcaseType || !$type->isEnabled()) {
            throw new NotFoundHttpException();
        }

        $locale = $request->getLocale();
        $withPrice = $this->filterBuilder->supportsPrice($type, []);
        $filter = $this->filterBuilder->fromRequest($request, $locale, $type, withPrice: $withPrice);

        $result = $this->paginator->paginate(
            $this->items->createFilteredQueryBuilder($filter),
            $request->query->getInt('page', 1),
            $this->config->itemsPerPage(),
        );

        $this->cacheTags->addListTag(ShowcaseItem::ENTITY_TYPE_ID, $type->getMachineName());

        $this->presenter->preload($result);

        return $this->render($this->templates->resolve('type'), $this->listingViewData($request, $locale, $type, $result, $withPrice) + [
            'featured' => [],
        ]);
    }

    /**
     * priority -1: every static segment under /showcase wins over the slug.
     */
    #[Route('/showcase/{slug}', name: 'showcase_show', methods: ['GET'], priority: -1)]
    public function show(string $slug, Request $request): Response
    {
        $locale = $request->getLocale();
        $item = $this->items->findOneVisibleBySlug($slug, $locale);

        if (!$item instanceof ShowcaseItem) {
            return $this->resolveCrossLocale($slug, $locale);
        }

        $this->itemManager->recordView($item);
        $this->cacheTags->addEntityTag(ShowcaseItem::ENTITY_TYPE_ID, (int) $item->getId());

        $reviewsResult = $item->getType()->supports('reviews') && $this->config->reviewsEnabled()
            ? $this->paginator->paginate($this->reviews->createApprovedQueryBuilder($item), $request->query->getInt('rpage', 1), 10)
            : null;

        $related = $this->relatedItems($item, $locale);

        // The "related" strip renders cards, so it needs the same batching the
        // listing pages get.
        $this->presenter->preload($related);

        return $this->render($this->templates->resolve('show'), [
            'showcaseParentLayout' => $this->templates->layout(),
            'item' => $item,
            'type' => $item->getType(),
            'fields' => $this->fieldDefinitions->getFieldsForBundle($item->fieldableBundle()),
            'fieldGroups' => $this->presenter->fieldGroups($item->getType()),
            'gallery' => $this->media->gallery($item),
            'resolvedLinks' => $this->links->resolveAll($item, $locale),
            'price' => $this->presenter->price($item, $locale),
            'coverAssetId' => $this->presenter->coverAssetId($item),
            'excerpt' => $this->presenter->excerpt($item),
            'externalHost' => $this->presenter->externalHost($item),
            'reviewsResult' => $reviewsResult,
            'canReview' => $this->access->canReview($item),
            'canEdit' => $this->access->canEdit($item),
            'myReview' => $this->currentUserReview($item),
            'related' => $related,
            'translations' => $this->items->findTranslationSiblings($item),
            'reviewCsrf' => self::REVIEW_CSRF,
        ]);
    }

    /**
     * Counts the outbound click and forwards to the seller's own address.
     *
     * The redirect target is re-validated here rather than trusted from the
     * column: a URL stored before a validator change must not become an open
     * redirect just because it is already in the database.
     */
    #[Route('/showcase/{slug}/visit', name: 'showcase_visit', methods: ['GET'], priority: 1)]
    public function visit(string $slug, Request $request): Response
    {
        $item = $this->items->findOneVisibleBySlug($slug, $request->getLocale());

        if (!$item instanceof ShowcaseItem) {
            throw new NotFoundHttpException();
        }

        $target = $this->urls->sanitize($item->getExternalUrl());

        if ($target === null) {
            return $this->redirectToRoute('showcase_show', ['_locale' => $item->getLocale(), 'slug' => $item->getSlug()]);
        }

        $this->itemManager->recordClick($item);

        return $this->redirect($target);
    }

    #[Route('/showcase/{slug}/review', name: 'showcase_review', methods: ['POST'], priority: 1)]
    public function review(string $slug, Request $request): Response
    {
        $item = $this->items->findOneVisibleBySlug($slug, $request->getLocale());

        if (!$item instanceof ShowcaseItem) {
            throw new NotFoundHttpException();
        }

        if (!$this->isCsrfTokenValid(self::REVIEW_CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }

        $user = $this->access->currentUser();

        if ($user === null || !$this->access->canReview($item)) {
            throw $this->createAccessDeniedException();
        }

        $result = $this->reviewService->submit(
            $item,
            $user,
            (int) $request->request->get('rating', 0),
            (string) $request->request->get('body', ''),
        );

        if ($result['review'] === null) {
            $this->addFlash('error', $this->translator->trans((string) $result['error']));
        } else {
            $this->addFlash('success', $this->translator->trans(
                $this->config->reviewsRequireApproval()
                    ? 'showcase.reviews.flash.pending'
                    : 'showcase.reviews.flash.published',
            ));
        }

        return $this->redirectToRoute('showcase_show', ['_locale' => $item->getLocale(), 'slug' => $item->getSlug()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listingViewData(Request $request, string $locale, ?ShowcaseType $type, mixed $result, bool $withPrice): array
    {
        $vocabulary = $type !== null ? $this->typeManager->vocabularyOf($type) : null;
        $categories = $vocabulary !== null ? $this->terms->findByVocabulary($vocabulary, $locale) : [];
        $facets = $type !== null ? $this->filterBuilder->facetsFor($type, $locale, $request) : [];

        return [
            'showcaseParentLayout' => $this->templates->layout(),
            'result' => $result,
            'type' => $type,
            'types' => $this->types->findEnabled(),
            'facets' => $facets,
            'categories' => $categories,
            'withPrice' => $withPrice,
            // Lets the sidebar decide whether it has anything worth rendering at
            // all, instead of drawing an empty panel titled "Filters".
            'hasFilters' => $withPrice || $facets !== [] || $categories !== [],
            'preserved' => $this->filterBuilder->preservedParams($request),
            'sorts' => $this->filterBuilder->sortsFor($withPrice),
            'currentSort' => ShowcaseFilter::normalizeSort($request->query->get('sort')),
            'layout' => $this->config->listingLayout(),
            'heroTitle' => $this->config->heroTitle($locale),
            'heroDescription' => $this->config->heroDescription($locale),
            'search' => (string) $request->query->get('q', ''),
            'canCreate' => $this->access->canCreate(),
            'routeName' => $type !== null ? 'showcase_type' : 'showcase_index',
            'routeParams' => $type !== null ? ['machineName' => $type->getMachineName()] : [],
        ];
    }

    /**
     * A slug that exists in another language is ordinary traffic — a shared link
     * that lost its locale prefix — not an application failure. Redirect to the
     * sibling when there is one; otherwise answer with the listing page and a 404
     * status so crawlers still learn the URL is gone.
     */
    private function resolveCrossLocale(string $slug, string $locale): Response
    {
        $fallback = $this->items->findOneBySlugAnyLocale($slug);

        if ($fallback instanceof ShowcaseItem) {
            $groupId = $fallback->getTranslationGroupId();
            $sibling = $groupId !== null ? $this->items->findOneByTranslationGroup($groupId, $locale) : null;
            $target = $sibling instanceof ShowcaseItem ? $sibling : $fallback;

            return $this->redirectToRoute('showcase_show', [
                '_locale' => $target->getLocale(),
                'slug' => $target->getSlug(),
            ]);
        }

        $response = $this->render($this->templates->resolve('not_found'), [
            'showcaseParentLayout' => $this->templates->layout(),
            'slug' => $slug,
            'types' => $this->types->findEnabled(),
        ]);
        $response->setStatusCode(Response::HTTP_NOT_FOUND);

        return $response;
    }

    /**
     * @return list<ShowcaseItem>
     */
    private function relatedItems(ShowcaseItem $item, string $locale): array
    {
        $related = $this->items->findVisible($locale, 5, featuredOnly: false, type: $item->getType());

        return array_values(array_filter(
            $related,
            static fn (ShowcaseItem $candidate): bool => $candidate->getId() !== $item->getId(),
        ));
    }

    private function currentUserReview(ShowcaseItem $item): mixed
    {
        $user = $this->access->currentUser();

        return $user instanceof User ? $this->reviews->findOneByItemAndAuthor($item, $user) : null;
    }
}
