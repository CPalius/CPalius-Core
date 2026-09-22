<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Content\SlugGenerator;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Pagination\Paginator;
use App\Core\Security\QueryScopeApplier;
use App\Core\Settings\SettingsRegistry;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\NodeFieldIndex;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\LocaleRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Modules\Blog\Event\BlogArticleCreatedEvent;
use Modules\Blog\Form\DTO\PostFormModel;
use Modules\Blog\Form\PostType;
use Modules\Blog\PostSubType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio CRUD for Node type "post" (Law 3.1). Auth via CPaliusVoter capabilities + QueryScopeApplier.
 * Forms use PostType/PostFormModel; persistence goes through mapDtoToNode().
 */
#[Route('/admin/posts', name: 'admin_posts_')]
final class PostAdminController extends AbstractController
{
    private const NODE_TYPE = 'post';

    private const ADMIN_PER_PAGE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly SlugGenerator $slugGenerator,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly AssetRepository $assetRepository,
        private readonly Paginator $paginator,
        private readonly LocaleRepository $localeRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
        private readonly OriginCachePurger $originCachePurger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    /**
     * List posts in one locale, or all locales when q is set. QueryScopeApplier adds author=:me for .own-only users (Law 6.2).
     */
    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.blog.menu', icon: 'heroicons:document-text', panel: 'studio', priority: 20, capability: 'node.post.view.own|node.post.view.any', group: 'studio.group.content')]
    public function index(Request $request): Response
    {
        // Coarse gate: own OR any. Per-row own/any is applied by QueryScopeApplier (Law 6.2).
        if (!$this->isGranted('node.post.view.own') && !$this->isGranted('node.post.view.any')) {
            throw $this->createAccessDeniedException($this->translator->trans('blog.posts.error.view_denied'));
        }

        $locale = $this->localeProvider->resolve($request->query->getString('locale') ?: null);
        $filters = $this->listFilters($request, $locale);
        $isSearch = $filters['q'] !== '';

        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', self::NODE_TYPE)
            ->orderBy('n.updatedAt', 'DESC');

        if ($isSearch) {
            $escaped = addcslashes($filters['q'], '%_\\');
            $qb->andWhere('(LOWER(n.title) LIKE LOWER(:q) OR LOWER(n.slug) LIKE LOWER(:q))')
                ->setParameter('q', '%'.$escaped.'%');
        } else {
            $qb->andWhere('n.locale = :locale')
                ->setParameter('locale', $locale);
        }

        $this->applyListFilters($qb, $filters);
        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        $queryParams = $this->listQueryParams($locale, $filters);
        $localeSwitchParams = $queryParams;
        unset($localeSwitchParams['category'], $localeSwitchParams['q']);

        $view = [
            'posts' => $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::ADMIN_PER_PAGE),
            'locales' => $this->localeProvider->getLocales(),
            'currentLocale' => $locale,
            'filters' => $filters,
            'queryParams' => $queryParams,
            'localeSwitchParams' => $localeSwitchParams,
            'authors' => $this->listAuthors(),
            'categories' => $this->categoryRepository->findByLocale($locale),
            'subTypes' => PostSubType::choices(),
            'statuses' => [
                Node::STATUS_DRAFT,
                Node::STATUS_PUBLISHED,
                Node::STATUS_SCHEDULED,
            ],
            'hasActiveFilters' => $this->hasActiveFilters($filters),
            'isSearch' => $isSearch,
            'createUrl' => $this->generateUrl('admin_posts_create', ['locale' => $locale]),
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('@BlogModule/admin/posts/_results.html.twig', $view);
        }

        return $this->render('@BlogModule/admin/posts/index.html.twig', $view);
    }

    /**
     * Same list as index(); exists so "Posts" appears inside the Blog dropdown.
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.blog.posts.menu', icon: 'heroicons:pencil-square', panel: 'studio', priority: 19, capability: 'node.post.view.own|node.post.view.any', parent: 'admin_posts_index')]
    public function list(Request $request): Response
    {
        return $this->index($request);
    }

    /**
     * @return array{
     *     q: string,
     *     author: int|null,
     *     category: int|null,
     *     type: string,
     *     status: string,
     *     date_from: string,
     *     date_to: string
     * }
     */
    private function listFilters(Request $request, string $locale): array
    {
        $subType = $request->query->getString('type');
        $status = $request->query->getString('status');
        $categoryId = $this->positiveIntOrNull($request->query->get('category'));
        if ($categoryId !== null) {
            $category = $this->categoryRepository->find($categoryId);
            if (!$category instanceof Term || $category->getLocale() !== $locale) {
                $categoryId = null;
            }
        }

        return [
            'q' => $this->normalizeSearchQuery($request->query->get('q')),
            'author' => $this->positiveIntOrNull($request->query->get('author')),
            'category' => $categoryId,
            'type' => PostSubType::isValid($subType) ? $subType : '',
            'status' => \in_array($status, [Node::STATUS_DRAFT, Node::STATUS_PUBLISHED, Node::STATUS_SCHEDULED], true) ? $status : '',
            'date_from' => $this->normalizeDateQuery($request->query->get('date_from')),
            'date_to' => $this->normalizeDateQuery($request->query->get('date_to')),
        ];
    }

    /**
     * @param array{q: string, author: int|null, category: int|null, type: string, status: string, date_from: string, date_to: string} $filters
     */
    private function applyListFilters(QueryBuilder $qb, array $filters): void
    {
        if ($filters['author'] !== null) {
            $qb->andWhere('IDENTITY(n.author) = :authorId')
                ->setParameter('authorId', $filters['author']);
        }

        if ($filters['category'] !== null) {
            $qb->innerJoin('n.categories', 'filterCategory')
                ->andWhere('filterCategory.id = :categoryId')
                ->setParameter('categoryId', $filters['category']);
        }

        if ($filters['type'] !== '') {
            $qb->innerJoin(NodeFieldIndex::class, 'nfi', 'WITH', 'nfi.node = n AND nfi.fieldName = :subTypeField')
                ->andWhere('nfi.valueString = :subType')
                ->setParameter('subTypeField', 'post_sub_type')
                ->setParameter('subType', $filters['type']);
        }

        if ($filters['status'] !== '') {
            $qb->andWhere('n.status = :status')
                ->setParameter('status', $filters['status']);
        }

        $from = $this->parseDayStart($filters['date_from']);
        if ($from instanceof \DateTimeImmutable) {
            $qb->andWhere('n.updatedAt >= :dateFrom')
                ->setParameter('dateFrom', $from);
        }

        $to = $this->parseDayEnd($filters['date_to']);
        if ($to instanceof \DateTimeImmutable) {
            $qb->andWhere('n.updatedAt <= :dateTo')
                ->setParameter('dateTo', $to);
        }
    }

    /**
     * @param array{q: string, author: int|null, category: int|null, type: string, status: string, date_from: string, date_to: string} $filters
     *
     * @return array<string, int|string>
     */
    private function listQueryParams(string $locale, array $filters): array
    {
        $params = ['locale' => $locale];
        foreach (['q', 'author', 'category', 'type', 'status', 'date_from', 'date_to'] as $key) {
            $value = $filters[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Extra filters only — search is not a "filter panel" state.
     *
     * @param array{q: string, author: int|null, category: int|null, type: string, status: string, date_from: string, date_to: string} $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        return $filters['author'] !== null
            || $filters['category'] !== null
            || $filters['type'] !== ''
            || $filters['status'] !== ''
            || $filters['date_from'] !== ''
            || $filters['date_to'] !== '';
    }

    /**
     * @return list<User>
     */
    private function listAuthors(): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->distinct()
            ->from(User::class, 'a')
            ->innerJoin(Node::class, 'n', 'WITH', 'n.author = a')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', self::NODE_TYPE)
            ->orderBy('a.username', 'ASC')
            ->addOrderBy('a.email', 'ASC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        return $qb->getQuery()->getResult();
    }

    private function positiveIntOrNull(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    private function normalizeSearchQuery(mixed $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > 80) {
            $value = mb_substr($value, 0, 80);
        }

        return $value;
    }

    private function normalizeDateQuery(mixed $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function parseDayStart(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value.' 00:00:00');
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDayEnd(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value.' 23:59:59');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Create may join an existing translation_group via query params; otherwise ungrouped.
     */
    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('node.post.create', null, $this->translator->trans('blog.posts.error.create_denied'));

        $translationGroupId = $this->parseTranslationGroupId($request->query->get('translation_group'));
        $targetLocale = $this->localeProvider->resolve(trim((string) $request->query->get('locale')));

        $dto = new PostFormModel();
        $form = $this->createPostForm($dto, $translationGroupId === null);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $node = new Node($dto->title, $this->resolveSlugForCreate($dto, $targetLocale), self::NODE_TYPE, $targetLocale);

            if ($translationGroupId !== null) {
                $node->joinTranslationGroup($translationGroupId);
            }

            $user = $this->getUser();
            if ($user instanceof User) {
                $node->setAuthor($user);
            }

            $this->mapDtoToNode($dto, $node);

            $this->entityManager->persist($node);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

            if ($dto->autoTranslate && $translationGroupId === null) {
                $this->eventDispatcher->dispatch(
                    new BlogArticleCreatedEvent($node, true),
                    BlogArticleCreatedEvent::NAME,
                );
            }

            $this->addFlash('success', $this->translator->trans('blog.posts.flash.created', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_posts_index');
        }

        return $this->render('@BlogModule/admin/posts/form.html.twig', $this->formViewData($form, $dto, $targetLocale, $translationGroupId, null));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.edit', $node, $this->translator->trans('blog.posts.error.edit_denied'));

        $dto = $this->buildDtoFromNode($node);
        $form = $this->createPostForm($dto, $this->allowEditTranslate(), true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedSlug = trim((string) $dto->slug);
            $slug = $submittedSlug !== '' && $submittedSlug !== $node->getSlug()
                ? $this->slugGenerator->generate($submittedSlug, $node->getLocale(), $node->getId())
                : $node->getSlug();

            $node->setTitle($dto->title);
            $node->setSlug($slug);

            $this->mapDtoToNode($dto, $node);

            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

            if ($dto->autoTranslate && $this->allowEditTranslate()) {
                $this->requestTranslation($node);
            }

            $this->addFlash('success', $this->translator->trans('blog.posts.flash.updated', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_posts_index');
        }

        return $this->render('@BlogModule/admin/posts/form.html.twig', $this->formViewData($form, $dto, $node->getLocale(), $node->getTranslationGroupId(), $node));
    }

    /**
     * Assign an ungrouped Node to a new translation group so locale badges can deep-link.
     */
    #[Route('/{id}/assign-translation-group', name: 'assign_translation_group', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignTranslationGroup(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.edit', $node, $this->translator->trans('blog.posts.error.edit_denied'));
        $this->assertValidCsrf($request, 'admin_post_form');

        if ($node->getTranslationGroupId() === null) {
            $node->assignToNewTranslationGroup();
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('admin_posts_edit', ['id' => $node->getId()]);
    }

    /**
     * Build PostType with locale-aware category choices injected by the controller.
     */
    private function createPostForm(PostFormModel $dto, bool $includeAutoTranslate = false, bool $autoTranslateEdit = false): FormInterface
    {
        $categories = $this->categoryRepository->findByLocale($this->localeProvider->getDefaultCode());
        $categoryChoices = [];
        foreach ($categories as $category) {
            $categoryChoices[$category->getName()] = $category;
        }

        return $this->createForm(PostType::class, $dto, [
            'category_choices' => $categoryChoices,
            'include_auto_translate' => $includeAutoTranslate,
            'auto_translate_edit' => $autoTranslateEdit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(FormInterface $form, PostFormModel $dto, string $locale, ?Uuid $translationGroupId, ?Node $post): array
    {
        $existing = $post instanceof Node ? $this->siblingLocales($post) : [];

        return [
            'post' => $post,
            'form' => $form,
            'featuredImageUrl' => $this->resolveAssetUrl($dto->featuredImageAssetId),
            'activeLocales' => $this->localeRepository->findActive(),
            'currentLocale' => $locale,
            'translations' => $post instanceof Node ? $this->buildTranslationsMap($post) : [],
            'translationGroupId' => $translationGroupId,
            'existingTranslationLocales' => $existing,
            'missingTranslationLocales' => $post instanceof Node ? $this->missingTranslationLocales($post, $existing) : [],
        ];
    }

    private function allowEditTranslate(): bool
    {
        return (string) $this->settingsRegistry->get('ai.allow_edit_translate', '0') === '1'
            && (string) $this->settingsRegistry->get('ai.enabled', '1') === '1';
    }

    private function requestTranslation(Node $node): void
    {
        $existing = $this->siblingLocales($node);
        if ($this->missingTranslationLocales($node, $existing) === []) {
            $this->addFlash('warning', $this->translator->trans('ai.flash.already_exists_node'));

            return;
        }

        $this->eventDispatcher->dispatch(
            new BlogArticleCreatedEvent($node, true),
            BlogArticleCreatedEvent::TRANSLATE,
        );
    }

    /**
     * @return list<string>
     */
    private function siblingLocales(Node $node): array
    {
        $locales = [];
        foreach (array_keys($this->buildTranslationsMap($node)) as $code) {
            if ($code !== $node->getLocale()) {
                $locales[] = $code;
            }
        }

        return $locales;
    }

    /**
     * @param list<string> $existingLocales
     *
     * @return list<string>
     */
    private function missingTranslationLocales(Node $node, array $existingLocales): array
    {
        $source = $node->getLocale();
        $missing = [];
        foreach ($this->localeProvider->getCodes() as $code) {
            if ($code === $source || \in_array($code, $existingLocales, true)) {
                continue;
            }
            $missing[] = $code;
        }

        return $missing;
    }

    private function resolveSlugForCreate(PostFormModel $dto, string $locale): string
    {
        $submittedSlug = trim((string) $dto->slug);

        return $submittedSlug !== ''
            ? $this->slugGenerator->generate($submittedSlug, $locale)
            : $this->slugGenerator->generate($dto->title, $locale);
    }

    /**
     * Sibling translations keyed by locale for the edit form language badges.
     *
     * @return array<string, Node>
     */
    private function buildTranslationsMap(Node $node): array
    {
        $groupId = $node->getTranslationGroupId();
        if ($groupId === null) {
            return [];
        }

        $map = [];
        foreach ($this->nodeRepository->findTranslations($groupId) as $translation) {
            $map[$translation->getLocale()] = $translation;
        }

        return $map;
    }

    private function parseTranslationGroupId(mixed $value): ?Uuid
    {
        $value = trim((string) $value);
        if ($value === '' || !Uuid::isValid($value)) {
            return null;
        }

        return Uuid::fromString($value);
    }

    /**
     * Map validated DTO onto Node + JSON data (Law 5.3 allowlist; body via RichTextSanitizer).
     */
    private function mapDtoToNode(PostFormModel $dto, Node $node): void
    {
        $this->applyPublicationSchedule($dto, $node);

        $node->setDataValue('excerpt', trim((string) $dto->excerpt));
        $node->setDataValue('body', $this->richTextSanitizer->sanitize($dto->body));
        // is_featured / post_sub_type are indexed via NodeIndexListener (Law 6.3).
        $node->setDataValue('is_featured', $dto->isFeatured ? 1 : 0);
        $node->setDataValue('comments_enabled', $dto->commentsEnabled ? 1 : 0);

        $node->setDataValue('post_sub_type', $dto->postSubType);
        $node->setDataValue('type_fields', $this->buildTypeFields($dto));

        $this->syncTaxonomy($dto, $node);

        $node->setDataValue('featured_image_asset_id', $dto->featuredImageAssetId);
        $node->setDataValue('seo', [
            'meta_description' => trim((string) $dto->seoMetaDescription) ?: null,
            'focus_keyword' => trim((string) $dto->seoFocusKeyword) ?: null,
            'og_image_asset_id' => null,
            'canonical_url' => trim((string) $dto->seoCanonicalUrl) ?: null,
            'schema_type' => 'BlogPosting',
            'noindex' => $dto->seoNoindex,
        ]);
    }

    /**
     * Coerce Studio status+publishedAt into draft/scheduled/published without leaking early.
     * published+future becomes scheduled; empty dates fall back to now for publish/schedule.
     */
    private function applyPublicationSchedule(PostFormModel $dto, Node $node): void
    {
        $requestedAt = $dto->publishedAt;

        if ($dto->status === Node::STATUS_DRAFT) {
            $node->setStatus(Node::STATUS_DRAFT);

            return;
        }

        if ($dto->status === Node::STATUS_SCHEDULED) {
            $node->setStatus(Node::STATUS_SCHEDULED);
            $node->setDataValue('scheduled_for', ($requestedAt ?? new \DateTimeImmutable())->format(DATE_ATOM));

            return;
        }

        // $dto->status === Node::STATUS_PUBLISHED
        $now = new \DateTimeImmutable();

        if ($requestedAt !== null && $requestedAt > $now) {
            // published + future date => schedule without calling Node::publish().
            $node->setStatus(Node::STATUS_SCHEDULED);
            $node->setDataValue('scheduled_for', $requestedAt->format(DATE_ATOM));

            return;
        }

        $node->publish($requestedAt ?? $now);
    }

    /**
     * Allowlist type_fields for the active postSubType only (Law 5.3).
     *
     * @return array<string, string|null>
     */
    private function buildTypeFields(PostFormModel $dto): array
    {
        return match ($dto->postSubType) {
            PostSubType::PROJECT => [
                'repo_url' => trim((string) $dto->projectRepoUrl) ?: null,
                'demo_url' => trim((string) $dto->projectDemoUrl) ?: null,
            ],
            PostSubType::SOFTWARE => [
                'version' => trim((string) $dto->softwareVersion) ?: null,
                'download_url' => trim((string) $dto->softwareDownloadUrl) ?: null,
            ],
            PostSubType::NOTE => [
                // Plain text only — never sanitized as HTML / never |raw.
                'code_snippet' => $dto->noteCodeSnippet !== null ? trim($dto->noteCodeSnippet) : null,
            ],
            default => [],
        };
    }

    /**
     * EntityType submits Term objects; findByIds() and find() speak ids.
     *
     * @param list<mixed> $values
     *
     * @return list<int>
     */
    private function normalizeCategoryIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if ($value instanceof Term) {
                $value = $value->getId();
            }
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Sync categories/tags from the DTO; first category becomes the primary.
     */
    private function syncTaxonomy(PostFormModel $dto, Node $node): void
    {
        foreach ($node->getCategories()->toArray() as $existing) {
            $node->removeCategory($existing);
        }

        $categoryIds = $this->normalizeCategoryIds($dto->categoryIds);
        if ($categoryIds !== []) {
            foreach ($this->categoryRepository->findByIds($categoryIds) as $category) {
                $node->addCategory($category);
            }
            $node->setCategory($this->categoryRepository->find($categoryIds[0]));
        } else {
            $node->setCategory(null);
        }

        $tagNames = array_values(array_filter(array_map('trim', explode(',', (string) $dto->tags))));
        foreach ($node->getTags()->toArray() as $existing) {
            $node->removeTag($existing);
        }
        foreach ($this->tagRepository->findOrCreateByNames($tagNames, $node->getLocale()) as $tag) {
            $node->addTag($tag);
        }
    }

    /**
     * Hydrate PostFormModel from an existing Node for the edit GET (manual JSON→DTO map).
     */
    private function buildDtoFromNode(Node $node): PostFormModel
    {
        $dto = new PostFormModel();
        $dto->title = $node->getTitle();
        $dto->slug = $node->getSlug();
        $dto->excerpt = (string) $node->getDataValue('excerpt', '');
        $dto->body = (string) $node->getDataValue('body', '');
        $dto->isFeatured = (bool) $node->getDataValue('is_featured', false);
        $rawComments = $node->getDataValue('comments_enabled', 1);
        $dto->commentsEnabled = !\in_array($rawComments, [0, '0', false, null, ''], true);
        $dto->status = $node->getStatus();
        $dto->publishedAt = $node->getPublishedAt() ?? $this->resolveScheduledForAsDate($node);
        $dto->categoryIds = array_map(static fn ($c) => $c->getId(), $node->getCategories()->toArray());
        $dto->tags = implode(', ', array_map(static fn ($t) => $t->getName(), $node->getTags()->toArray()));

        $featuredAssetId = $node->getDataValue('featured_image_asset_id');
        $dto->featuredImageAssetId = is_numeric($featuredAssetId) ? (int) $featuredAssetId : null;

        $seo = $node->getDataValue('seo', []);
        $dto->seoMetaDescription = is_array($seo) ? (string) ($seo['meta_description'] ?? '') : '';
        $dto->seoFocusKeyword = is_array($seo) ? (string) ($seo['focus_keyword'] ?? '') : '';
        $dto->seoCanonicalUrl = is_array($seo) ? (string) ($seo['canonical_url'] ?? '') : '';
        $dto->seoNoindex = is_array($seo) && (bool) ($seo['noindex'] ?? false);

        $subType = (string) $node->getDataValue('post_sub_type', PostSubType::ARTICLE);
        $dto->postSubType = PostSubType::isValid($subType) ? $subType : PostSubType::ARTICLE;

        $typeFields = $node->getDataValue('type_fields', []);
        if (is_array($typeFields)) {
            $dto->projectRepoUrl = (string) ($typeFields['repo_url'] ?? '') ?: null;
            $dto->projectDemoUrl = (string) ($typeFields['demo_url'] ?? '') ?: null;
            $dto->softwareVersion = (string) ($typeFields['version'] ?? '') ?: null;
            $dto->softwareDownloadUrl = (string) ($typeFields['download_url'] ?? '') ?: null;
            $dto->noteCodeSnippet = (string) ($typeFields['code_snippet'] ?? '') ?: null;
        }

        return $dto;
    }

    /**
     * For scheduled posts, read scheduled_for from JSON when publishedAt is still null.
     */
    private function resolveScheduledForAsDate(Node $node): ?\DateTimeImmutable
    {
        $scheduledFor = $node->getDataValue('scheduled_for');
        if (!is_string($scheduledFor) || $scheduledFor === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($scheduledFor);
        } catch (\Exception) {
            return null;
        }
    }

    private function resolveAssetUrl(?int $assetId): ?string
    {
        if ($assetId === null) {
            return null;
        }

        $asset = $this->assetRepository->find($assetId);

        return $asset?->getStorageKey() !== null ? '/uploads/'.$asset->getStorageKey() : null;
    }

    #[Route('/{id}/publish', name: 'publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);

        $this->denyAccessUnlessGranted('node.post.publish', null, $this->translator->trans('blog.posts.error.publish_denied'));
        $this->assertValidCsrf($request, 'admin_post_form');

        $node->publish();
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true]);
        }

        $this->addFlash('success', $this->translator->trans('blog.posts.flash.published', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_posts_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.delete', $node, $this->translator->trans('blog.posts.error.delete_denied'));
        $this->assertValidCsrf($request, 'admin_post_form');

        // Soft-delete stamps deletedAt (trash) instead of a hard DELETE.
        $node->softDelete();
        $this->entityManager->flush();
        $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true]);
        }

        $this->addFlash('success', $this->translator->trans('blog.posts.flash.trashed', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_posts_index');
    }

    private function findPostOrFail(int $id): Node
    {
        $node = $this->nodeRepository->find($id);

        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE || $node->getDeletedAt() !== null) {
            throw new NotFoundHttpException($this->translator->trans('blog.posts.error.not_found'));
        }

        return $node;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }

    /**
     * Try .any first (subject-less), then .own with subject — order matters for admins.
     */
    private function assertOwnOrAny(string $capabilityBase, Node $subject, string $message): void
    {
        if ($this->isGranted($capabilityBase.'.any')) {
            return;
        }

        $this->denyAccessUnlessGranted($capabilityBase.'.own', $subject, $message);
    }
}
