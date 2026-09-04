<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Content\SlugGenerator;
use App\Core\Localization\LocaleProvider;
use App\Core\Pagination\Paginator;
use App\Core\Security\QueryScopeApplier;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\LocaleRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Form\DTO\PostFormModel;
use Modules\Blog\Form\PostType;
use Modules\Blog\PostSubType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
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
    ) {
    }

    /**
     * List posts; QueryScopeApplier adds author=:me for .own-only users (Law 6.2).
     */
    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Blog', icon: 'heroicons:document-text', panel: 'studio', priority: 20, capability: 'node.post.view.own|node.post.view.any', group: 'İçerik')]
    public function index(Request $request): Response
    {
        // Coarse gate: own OR any. Per-row own/any is applied by QueryScopeApplier (Law 6.2).
        if (!$this->isGranted('node.post.view.own') && !$this->isGranted('node.post.view.any')) {
            throw $this->createAccessDeniedException($this->translator->trans('blog.posts.error.view_denied'));
        }

        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', self::NODE_TYPE)
            ->orderBy('n.updatedAt', 'DESC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        $result = $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::ADMIN_PER_PAGE);

        return $this->render('@BlogModule/admin/posts/index.html.twig', [
            'posts' => $result,
        ]);
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
        $form = $this->createPostForm($dto);
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

            $this->addFlash('success', $this->translator->trans('blog.posts.flash.created', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_posts_index');
        }

        return $this->render('@BlogModule/admin/posts/form.html.twig', [
            'post' => null,
            'form' => $form,
            'featuredImageUrl' => $this->resolveAssetUrl($dto->featuredImageAssetId),
            'activeLocales' => $this->localeRepository->findActive(),
            'currentLocale' => $targetLocale,
            'translations' => [],
            'translationGroupId' => $translationGroupId,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.edit', $node, $this->translator->trans('blog.posts.error.edit_denied'));

        $dto = $this->buildDtoFromNode($node);
        $form = $this->createPostForm($dto);
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

            $this->addFlash('success', $this->translator->trans('blog.posts.flash.updated', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_posts_index');
        }

        return $this->render('@BlogModule/admin/posts/form.html.twig', [
            'post' => $node,
            'form' => $form,
            'featuredImageUrl' => $this->resolveAssetUrl($dto->featuredImageAssetId),
            'activeLocales' => $this->localeRepository->findActive(),
            'currentLocale' => $node->getLocale(),
            'translations' => $this->buildTranslationsMap($node),
            'translationGroupId' => $node->getTranslationGroupId(),
        ]);
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
    private function createPostForm(PostFormModel $dto): FormInterface
    {
        $categories = $this->categoryRepository->findBy(['locale' => $this->localeProvider->getDefaultCode()]);
        $categoryChoices = [];
        foreach ($categories as $category) {
            $categoryChoices[$category->getName()] = $category;
        }

        return $this->createForm(PostType::class, $dto, [
            'category_choices' => $categoryChoices,
        ]);
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
     * Sync categories/tags from the DTO; first category becomes the primary.
     */
    private function syncTaxonomy(PostFormModel $dto, Node $node): void
    {
        foreach ($node->getCategories()->toArray() as $existing) {
            $node->removeCategory($existing);
        }

        if ($dto->categoryIds !== []) {
            foreach ($this->categoryRepository->findBy(['id' => $dto->categoryIds]) as $category) {
                $node->addCategory($category);
            }
            $node->setCategory($this->categoryRepository->find($dto->categoryIds[0]));
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
