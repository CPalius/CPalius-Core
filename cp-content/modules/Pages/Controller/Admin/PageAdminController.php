<?php

declare(strict_types=1);

namespace Modules\Pages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Content\SlugGenerator;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Pagination\Paginator;
use App\Core\Security\QueryScopeApplier;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\LocaleRepository;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Pages\Event\PageCreatedEvent;
use Modules\Pages\Field\PageFieldNormalizer;
use Modules\Pages\Field\PageFieldPresets;
use Modules\Pages\Field\PageFieldType;
use Modules\Pages\Form\DTO\PageFormModel;
use Modules\Pages\Form\PageType;
use Modules\Pages\PageReservedSlugs;
use Modules\Pages\PageTemplate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio CRUD for Node type "page" (Law 3.1).
 */
#[Route('/admin/pages', name: 'admin_pages_')]
final class PageAdminController extends AbstractController
{
    public const NODE_TYPE = 'page';

    private const ADMIN_PER_PAGE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly SlugGenerator $slugGenerator,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly PageFieldNormalizer $fieldNormalizer,
        private readonly AssetRepository $assetRepository,
        private readonly Paginator $paginator,
        private readonly LocaleRepository $localeRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
        private readonly OriginCachePurger $originCachePurger,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.pages.menu', icon: 'heroicons:document-duplicate', panel: 'studio', priority: 18, capability: 'node.page.view.own|node.page.view.any', group: 'studio.group.content')]
    public function index(Request $request): Response
    {
        if (!$this->isGranted('node.page.view.own') && !$this->isGranted('node.page.view.any')) {
            throw $this->createAccessDeniedException($this->translator->trans('pages.error.view_denied'));
        }

        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', self::NODE_TYPE)
            ->orderBy('n.updatedAt', 'DESC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.page.view', 'author');

        $result = $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::ADMIN_PER_PAGE);

        return $this->render('@PagesModule/admin/pages/index.html.twig', [
            'pages' => $result,
        ]);
    }

    #[Route('/list', name: 'list', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.pages.pages.menu', icon: 'heroicons:document', panel: 'studio', priority: 17, capability: 'node.page.view.own|node.page.view.any', parent: 'admin_pages_index')]
    public function list(Request $request): Response
    {
        return $this->index($request);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('node.page.create', null, $this->translator->trans('pages.error.create_denied'));

        $translationGroupId = $this->parseTranslationGroupId($request->query->get('translation_group'));
        $targetLocale = $this->localeProvider->resolve(trim((string) $request->query->get('locale')));

        $dto = new PageFormModel();
        $defaultTemplate = (string) $this->settingsRegistry->get('pages.default_template', PageTemplate::DEFAULT);
        $dto->template = PageTemplate::isValid($defaultTemplate) ? $defaultTemplate : PageTemplate::DEFAULT;
        $preset = trim((string) $request->query->get('preset'));
        if ($preset !== '') {
            $this->applyPresetToDto($dto, $preset, $targetLocale);
        }

        $form = $this->createPageForm($dto, $translationGroupId === null);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $slug = $this->resolveSlugForCreate($dto, $targetLocale);
            $node = new Node($dto->title, $slug, self::NODE_TYPE, $targetLocale);

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
            $this->purgePageCache($node);

            if ($dto->autoTranslate && $translationGroupId === null) {
                $this->eventDispatcher->dispatch(
                    new PageCreatedEvent($node, true),
                    PageCreatedEvent::NAME,
                );
            }

            $this->addFlash('success', $this->translator->trans('pages.flash.created', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_pages_index');
        }

        return $this->render('@PagesModule/admin/pages/form.html.twig', $this->formViewData($form, $dto, $targetLocale, $translationGroupId, null));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $node = $this->findPageOrFail($id);
        $this->assertOwnOrAny('node.page.edit', $node, $this->translator->trans('pages.error.edit_denied'));

        $previousSlug = $node->getSlug();
        $dto = $this->buildDtoFromNode($node);
        $form = $this->createPageForm($dto, $this->allowEditTranslate(), true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedSlug = trim((string) $dto->slug);
            $slug = $submittedSlug !== '' && $submittedSlug !== $node->getSlug()
                ? $this->slugGenerator->generate($submittedSlug, $node->getLocale(), $node->getId())
                : $node->getSlug();

            if (PageReservedSlugs::isReserved($slug)) {
                $this->addFlash('error', $this->translator->trans('pages.error.reserved_slug'));

                return $this->render('@PagesModule/admin/pages/form.html.twig', $this->formViewData($form, $dto, $node->getLocale(), $node->getTranslationGroupId(), $node));
            }

            $node->setTitle($dto->title);
            $node->setSlug($slug);
            $this->mapDtoToNode($dto, $node);

            $this->entityManager->flush();
            $this->purgePageCache($node, $previousSlug);

            if ($dto->autoTranslate && $this->allowEditTranslate()) {
                $this->requestTranslation($node);
            }

            $this->addFlash('success', $this->translator->trans('pages.flash.updated', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_pages_index');
        }

        return $this->render('@PagesModule/admin/pages/form.html.twig', $this->formViewData($form, $dto, $node->getLocale(), $node->getTranslationGroupId(), $node));
    }

    #[Route('/{id}/assign-translation-group', name: 'assign_translation_group', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignTranslationGroup(int $id, Request $request): Response
    {
        $node = $this->findPageOrFail($id);
        $this->assertOwnOrAny('node.page.edit', $node, $this->translator->trans('pages.error.edit_denied'));
        $this->assertValidCsrf($request, 'admin_page_form');

        if ($node->getTranslationGroupId() === null) {
            $node->assignToNewTranslationGroup();
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('admin_pages_edit', ['id' => $node->getId()]);
    }

    #[Route('/{id}/publish', name: 'publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id, Request $request): Response
    {
        $node = $this->findPageOrFail($id);
        $this->denyAccessUnlessGranted('node.page.publish', null, $this->translator->trans('pages.error.publish_denied'));
        $this->assertValidCsrf($request, 'admin_page_form');

        $node->publish();
        $this->entityManager->flush();
        $this->purgePageCache($node);

        $this->addFlash('success', $this->translator->trans('pages.flash.published', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_pages_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $node = $this->findPageOrFail($id);
        $this->assertOwnOrAny('node.page.delete', $node, $this->translator->trans('pages.error.delete_denied'));
        $this->assertValidCsrf($request, 'admin_page_form');

        $slug = $node->getSlug();
        $node->softDelete();
        $this->entityManager->flush();
        $this->purgePageCache($node, $slug);

        $this->addFlash('success', $this->translator->trans('pages.flash.trashed', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_pages_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(FormInterface $form, PageFormModel $dto, string $locale, ?Uuid $translationGroupId, ?Node $page): array
    {
        $existing = $page instanceof Node ? $this->siblingLocales($page) : [];

        return [
            'page' => $page,
            'form' => $form,
            'featuredImageUrl' => $this->resolveAssetUrl($dto->featuredImageAssetId),
            'activeLocales' => $this->localeRepository->findActive(),
            'currentLocale' => $locale,
            'translations' => $page instanceof Node ? $this->buildTranslationsMap($page) : [],
            'translationGroupId' => $translationGroupId,
            'fieldGroups' => $this->fieldGroupsForLocale($locale),
            'fieldTypeChoices' => PageFieldType::choices(),
            'presets' => PageFieldPresets::all($this->translator, $locale),
            'existingTranslationLocales' => $existing,
            'missingTranslationLocales' => $page instanceof Node ? $this->missingTranslationLocales($page, $existing) : [],
        ];
    }

    private function createPageForm(PageFormModel $dto, bool $includeAutoTranslate = false, bool $autoTranslateEdit = false): FormInterface
    {
        return $this->createForm(PageType::class, $dto, [
            'include_auto_translate' => $includeAutoTranslate,
            'auto_translate_edit' => $autoTranslateEdit,
        ]);
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
            new PageCreatedEvent($node, true),
            PageCreatedEvent::TRANSLATE,
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

    private function resolveSlugForCreate(PageFormModel $dto, string $locale): string
    {
        $submittedSlug = trim((string) $dto->slug);
        $slug = $submittedSlug !== ''
            ? $this->slugGenerator->generate($submittedSlug, $locale)
            : $this->slugGenerator->generate($dto->title, $locale);

        if (PageReservedSlugs::isReserved($slug)) {
            $slug = $this->slugGenerator->generate($slug.'-page', $locale);
        }

        return $slug;
    }

    /**
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
            if ($translation->getType() === self::NODE_TYPE) {
                $map[$translation->getLocale()] = $translation;
            }
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

    private function mapDtoToNode(PageFormModel $dto, Node $node): void
    {
        $this->applyPublicationSchedule($dto, $node);

        $node->setDataValue('excerpt', trim((string) $dto->excerpt));
        $node->setDataValue('body', $this->richTextSanitizer->sanitize($dto->body));
        $node->setDataValue('is_featured', $dto->isFeatured ? 1 : 0);
        $node->setDataValue('template', PageTemplate::isValid($dto->template) ? $dto->template : PageTemplate::DEFAULT);
        $node->setDataValue('featured_image_asset_id', $dto->featuredImageAssetId);
        $node->setDataValue('field_group_id', $dto->fieldGroupId && $dto->fieldGroupId > 0 ? $dto->fieldGroupId : null);
        $node->setDataValue('custom_fields', $this->decodeAndNormalizeFields($dto->customFieldsJson, true));
        $node->setDataValue('custom_css', $this->fieldNormalizer->sanitizeCss((string) $dto->customCss));
        $node->setDataValue('custom_js', $this->fieldNormalizer->sanitizeJs((string) $dto->customJs));
        $node->setDataValue('seo', [
            'meta_description' => trim((string) $dto->seoMetaDescription) ?: null,
            'focus_keyword' => trim((string) $dto->seoFocusKeyword) ?: null,
            'og_image_asset_id' => null,
            'canonical_url' => trim((string) $dto->seoCanonicalUrl) ?: null,
            'schema_type' => 'WebPage',
            'noindex' => $dto->seoNoindex,
        ]);
    }

    private function applyPublicationSchedule(PageFormModel $dto, Node $node): void
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

        $now = new \DateTimeImmutable();
        if ($requestedAt !== null && $requestedAt > $now) {
            $node->setStatus(Node::STATUS_SCHEDULED);
            $node->setDataValue('scheduled_for', $requestedAt->format(DATE_ATOM));

            return;
        }

        $node->publish($requestedAt ?? $now);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeAndNormalizeFields(string $json, bool $includeValues): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = [];
        }

        if (!\is_array($decoded)) {
            $decoded = [];
        }

        /* @var list<mixed> $decoded */
        return $this->fieldNormalizer->normalizeList($decoded, $includeValues);
    }

    private function buildDtoFromNode(Node $node): PageFormModel
    {
        $dto = new PageFormModel();
        $dto->title = $node->getTitle();
        $dto->slug = $node->getSlug();
        $dto->excerpt = (string) $node->getDataValue('excerpt', '');
        $dto->body = (string) $node->getDataValue('body', '');
        $dto->isFeatured = (bool) $node->getDataValue('is_featured', false);
        $dto->status = $node->getStatus();
        $dto->publishedAt = $node->getPublishedAt() ?? $this->resolveScheduledForAsDate($node);

        $template = (string) $node->getDataValue('template', PageTemplate::DEFAULT);
        $dto->template = PageTemplate::isValid($template) ? $template : PageTemplate::DEFAULT;

        $featuredAssetId = $node->getDataValue('featured_image_asset_id');
        $dto->featuredImageAssetId = is_numeric($featuredAssetId) ? (int) $featuredAssetId : null;

        $groupId = $node->getDataValue('field_group_id');
        $dto->fieldGroupId = is_numeric($groupId) ? (int) $groupId : null;

        $fields = $node->getDataValue('custom_fields', []);
        $dto->customFieldsJson = json_encode(\is_array($fields) ? $fields : [], \JSON_UNESCAPED_UNICODE) ?: '[]';
        $dto->customCss = (string) $node->getDataValue('custom_css', '');
        $dto->customJs = (string) $node->getDataValue('custom_js', '');

        $seo = $node->getDataValue('seo', []);
        $dto->seoMetaDescription = is_array($seo) ? (string) ($seo['meta_description'] ?? '') : '';
        $dto->seoFocusKeyword = is_array($seo) ? (string) ($seo['focus_keyword'] ?? '') : '';
        $dto->seoCanonicalUrl = is_array($seo) ? (string) ($seo['canonical_url'] ?? '') : '';
        $dto->seoNoindex = is_array($seo) && (bool) ($seo['noindex'] ?? false);

        return $dto;
    }

    private function applyPresetToDto(PageFormModel $dto, string $identifier, string $locale): void
    {
        foreach (PageFieldPresets::all($this->translator, $locale) as $preset) {
            if ($preset['identifier'] !== $identifier) {
                continue;
            }
            $dto->customFieldsJson = json_encode($preset['fields'], \JSON_UNESCAPED_UNICODE) ?: '[]';
            if (trim($dto->title) === '') {
                $dto->title = $preset['title'];
            }
            if ($identifier === 'fg-landing') {
                $dto->template = PageTemplate::LANDING;
            }

            return;
        }
    }

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

    /**
     * @return list<array{id: int, title: string, identifier: string, fields: list<array<string, mixed>>}>
     */
    private function fieldGroupsForLocale(string $locale): array
    {
        $nodes = $this->nodeRepository->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.status = :status')
            ->setParameter('type', PageFieldNormalizer::NODE_TYPE_FIELD_GROUP)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.title', 'ASC')
            ->getQuery()
            ->getResult();

        $groups = [];
        foreach ($nodes as $node) {
            if (!$node instanceof Node || $node->getId() === null) {
                continue;
            }
            $fields = $node->getDataValue('fields', []);
            $groups[] = [
                'id' => $node->getId(),
                'title' => $node->getTitle(),
                'identifier' => $node->getSlug(),
                'fields' => \is_array($fields) ? $fields : [],
            ];
        }

        return $groups;
    }

    private function resolveAssetUrl(?int $assetId): ?string
    {
        if ($assetId === null) {
            return null;
        }

        $asset = $this->assetRepository->find($assetId);

        return $asset?->getStorageKey() !== null ? '/uploads/'.$asset->getStorageKey() : null;
    }

    private function purgePageCache(Node $node, ?string $previousSlug = null): void
    {
        $areas = ['home', $node->getSlug()];
        if ($previousSlug !== null && $previousSlug !== $node->getSlug()) {
            $areas[] = $previousSlug;
        }
        $this->originCachePurger->purgeAreas(...$areas);
    }

    private function findPageOrFail(int $id): Node
    {
        $node = $this->nodeRepository->find($id);

        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE || $node->getDeletedAt() !== null) {
            throw new NotFoundHttpException($this->translator->trans('pages.error.not_found'));
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

    private function assertOwnOrAny(string $capabilityBase, Node $subject, string $message): void
    {
        if ($this->isGranted($capabilityBase.'.any')) {
            return;
        }

        $this->denyAccessUnlessGranted($capabilityBase.'.own', $subject, $message);
    }
}
