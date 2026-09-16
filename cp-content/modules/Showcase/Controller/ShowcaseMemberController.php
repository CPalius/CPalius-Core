<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller;

use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Localization\LocaleProvider;
use App\Core\Pagination\Paginator;
use App\Core\Security\Flood\FloodService;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Core\TextFormat\TextFormatAccess;
use App\Entity\User;
use Modules\Showcase\Dto\ShowcaseItemInput;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Query\ShowcaseFilter;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcaseAccess;
use Modules\Showcase\Service\ShowcaseConfig;
use Modules\Showcase\Service\ShowcaseFieldUploadHandler;
use Modules\Showcase\Service\ShowcaseItemManager;
use Modules\Showcase\Service\ShowcaseLinkService;
use Modules\Showcase\Service\ShowcaseMediaService;
use Modules\Showcase\Service\ShowcaseTemplateResolver;
use Modules\Showcase\Service\ShowcaseTypeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The member-facing half of the showcase: "my entries", the submission form and
 * everything an owner can do to their own listing.
 *
 * Every write re-checks ownership through ShowcaseAccess rather than trusting the
 * id in the URL, and every write goes through ShowcaseItemManager, so the public
 * form is held to exactly the same sanitization as the admin screen.
 *
 * Routes sit at priority 5 so "/showcase/me" is never swallowed by the
 * "/showcase/{slug}" detail route.
 */
#[Route('/showcase/me', name: 'showcase_member_', priority: 5)]
#[IsGranted('showcase.item.create')]
final class ShowcaseMemberController extends AbstractController
{
    private const CSRF_TOKEN = 'showcase_member';
    private const PER_PAGE = 12;

    /** Flood-control window for submissions, in seconds. */
    private const SUBMIT_WINDOW = 3600;

    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseItemManager $itemManager,
        private readonly ShowcaseMediaService $media,
        private readonly ShowcaseLinkService $links,
        private readonly ShowcaseFieldUploadHandler $fieldUploads,
        private readonly ShowcaseTypeManager $typeManager,
        private readonly ShowcaseTemplateResolver $templates,
        private readonly ShowcaseAccess $access,
        private readonly ShowcaseConfig $config,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly TextFormatAccess $textFormats,
        private readonly TermRepository $terms,
        private readonly LocaleProvider $localeProvider,
        private readonly Paginator $paginator,
        private readonly FloodService $flood,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        $filter = new ShowcaseFilter(
            locale: $request->getLocale(),
            statuses: ShowcaseItem::STATUSES,
            ownerId: $user->getId(),
            sort: ShowcaseFilter::SORT_RECENT,
            includeExpired: true,
            anyLocale: true,
        );

        $result = $this->paginator->paginate(
            $this->items->createFilteredQueryBuilder($filter),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render($this->templates->resolve('member/index'), [
            'showcaseParentLayout' => $this->templates->layout(),
            'result' => $result,
            'types' => $this->types->findEnabled(),
            'quota' => $this->quotaState($user),
        ]);
    }

    #[Route('/new', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->requireUser();
        $type = $this->typeFrom($request->isMethod('POST') ? $request->request->get('type') : $request->query->get('type'));

        if (!$type instanceof ShowcaseType) {
            return $this->render($this->templates->resolve('member/choose_type'), [
                'showcaseParentLayout' => $this->templates->layout(),
                'types' => $this->types->findEnabled(),
            ]);
        }

        $quota = $this->quotaState($user);

        if ($quota['exceeded']) {
            $this->addFlash('error', $this->translator->trans('showcase.member.error.quota', ['limit' => $quota['limit']]));

            return $this->redirectToRoute('showcase_member_index');
        }

        $locale = $this->localeProvider->resolve($request->getLocale());

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $this->assertNotFlooding($user);

            $result = $this->itemManager->create(
                $type,
                $user,
                $locale,
                $this->inputFrom($request, $type),
                $this->access->canPublishDirectly(),
            );

            if ($result['item'] === null) {
                return $this->renderForm(null, $type, $locale, $request, $result['errors']);
            }

            // Images posted with the create form are attached now that the entry
            // has an id. Doing it here rather than making people save first is
            // the difference between "add your product" and "add your product,
            // then come back and add pictures".
            $this->storeUploads($result['item'], $request);
            $this->registerSubmission($user);

            $this->addFlash('success', $this->translator->trans(
                $result['item']->isPublished()
                    ? 'showcase.member.flash.published'
                    : 'showcase.member.flash.pending',
            ));

            return $this->redirectToRoute('showcase_member_edit', ['id' => $result['item']->getId()]);
        }

        return $this->renderForm(null, $type, $locale, $request, []);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $item = $this->findOwnedItem($id);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            $errors = $this->itemManager->update(
                $item,
                $this->inputFrom($request, $item->getType()),
                $this->access->canPublishDirectly(),
            );

            if ($errors !== []) {
                return $this->renderForm($item, $item->getType(), $item->getLocale(), $request, $errors);
            }

            $this->addFlash('success', $this->translator->trans('showcase.member.flash.saved'));

            return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
        }

        return $this->renderForm($item, $item->getType(), $item->getLocale(), $request, []);
    }

    #[Route('/{id}/submit', name: 'submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        $this->itemManager->submitForReview($item, $this->access->canPublishDirectly());

        $this->addFlash('success', $this->translator->trans(
            $item->isPublished() ? 'showcase.member.flash.published' : 'showcase.member.flash.pending',
        ));

        return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
    }

    #[Route('/{id}/archive', name: 'archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        $this->itemManager->archive($item);
        $this->addFlash('success', $this->translator->trans('showcase.member.flash.archived'));

        return $this->redirectToRoute('showcase_member_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        if (!$this->access->canDelete($item)) {
            throw $this->createAccessDeniedException();
        }

        // Members get the recycle bin, never a hard delete: an accidental click
        // must be recoverable by a moderator.
        $this->itemManager->softDelete($item);
        $this->addFlash('success', $this->translator->trans('showcase.member.flash.deleted'));

        return $this->redirectToRoute('showcase_member_index');
    }

    #[Route('/{id}/media', name: 'media_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addMedia(int $id, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        $this->storeUploads($item, $request);

        return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
    }

    /**
     * Attaches whatever came in on "images[]", reporting per-file problems.
     * Shared by the create form and the gallery box so both behave identically.
     */
    private function storeUploads(ShowcaseItem $item, Request $request): void
    {
        $files = $request->files->all()['images'] ?? [];
        $result = $this->media->addUploads($item, \is_array($files) ? array_values($files) : []);

        foreach (array_unique($result['errors']) as $error) {
            $this->addFlash('error', $this->translator->trans($error));
        }

        if ($result['added'] > 0) {
            $this->addFlash('success', $this->translator->trans('showcase.media.flash.added', ['count' => $result['added']]));
        }
    }

    #[Route('/{id}/media/{mediaId}/delete', name: 'media_delete', methods: ['POST'], requirements: ['id' => '\d+', 'mediaId' => '\d+'])]
    public function deleteMedia(int $id, int $mediaId, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        $this->media->remove($item, $mediaId);

        return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
    }

    #[Route('/{id}/media/{mediaId}/cover', name: 'media_cover', methods: ['POST'], requirements: ['id' => '\d+', 'mediaId' => '\d+'])]
    public function setCover(int $id, int $mediaId, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        foreach ($item->getMedia() as $media) {
            if ($media->getId() === $mediaId) {
                $this->media->setCover($item, $media->getAssetId());
                break;
            }
        }

        return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
    }

    /**
     * Attaches a link to a forum thread, a blog post or an external address.
     * The owner pastes a URL; ShowcaseLinkService decides whether it is a route
     * in this installation or an outside address, and refuses private areas.
     */
    #[Route('/{id}/links', name: 'link_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachLink(int $id, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        $result = $this->links->attach(
            $item,
            (string) $request->request->get('link_url', ''),
            (string) $request->request->get('link_label', ''),
        );

        if ($result['link'] === null) {
            $this->addFlash('error', $this->translator->trans((string) $result['error']));
        } else {
            $this->addFlash('success', $this->translator->trans('showcase.links.flash.added'));
        }

        return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
    }

    #[Route('/{id}/links/{linkId}/delete', name: 'link_delete', methods: ['POST'], requirements: ['id' => '\d+', 'linkId' => '\d+'])]
    public function detachLink(int $id, int $linkId, Request $request): Response
    {
        $item = $this->findOwnedItem($id);
        $this->assertCsrf($request);

        foreach ($item->getLinks() as $link) {
            if ($link->getId() === $linkId) {
                $this->links->detach($item, $link);
                break;
            }
        }

        return $this->redirectToRoute('showcase_member_edit', ['id' => $id]);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function renderForm(?ShowcaseItem $item, ShowcaseType $type, string $locale, Request $request, array $errors): Response
    {
        $vocabulary = $this->typeManager->vocabularyOf($type);

        return $this->render($this->templates->resolve('member/form'), [
            'showcaseParentLayout' => $this->templates->layout(),
            'item' => $item,
            'type' => $type,
            'locale' => $locale,
            'fields' => $this->fieldDefinitions->getFieldsForBundle($type->fieldBundle()),
            'fieldValues' => $request->isMethod('POST') ? $request->request->all('fields') : ($item?->getFieldableData() ?? []),
            'errors' => $errors,
            'priceModes' => ShowcaseItem::PRICE_MODES,
            'defaultCurrency' => $this->config->defaultCurrency(),
            'formatChoices' => $this->textFormats->usableChoices(),
            'categoryTerms' => $vocabulary !== null ? $this->terms->findByVocabulary($vocabulary, $locale) : [],
            'selectedTerms' => $this->selectedTermIds($item, $request),
            'gallery' => $item !== null ? $this->media->gallery($item) : [],
            'galleryLimit' => $this->config->maxGalleryImages(),
            'resolvedLinks' => $item !== null ? $this->links->resolveAll($item, $locale) : [],
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    /**
     * Reads the submitted form and folds any uploaded image/file field values in
     * as asset ids, so the manager sees one uniform "fields" array.
     */
    private function inputFrom(Request $request, ShowcaseType $type): ShowcaseItemInput
    {
        $input = ShowcaseItemInput::fromRequest($request);
        $merged = $this->fieldUploads->merge($request, $type->fieldBundle(), $input->fields);

        foreach ($merged['errors'] as $error) {
            $this->addFlash('error', $this->translator->trans($error));
        }

        return $input->withFields($merged['fields']);
    }

    /**
     * @return list<int>
     */
    private function selectedTermIds(?ShowcaseItem $item, Request $request): array
    {
        if ($request->isMethod('POST')) {
            return ShowcaseItemInput::fromRequest($request)->termIds;
        }

        $ids = [];

        foreach ($item?->getTerms() ?? [] as $term) {
            $id = $term->getId();

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array{limit: int, used: int, exceeded: bool}
     */
    private function quotaState(User $user): array
    {
        $limit = $this->config->maxItemsPerMember();

        if ($limit === 0) {
            return ['limit' => 0, 'used' => 0, 'exceeded' => false];
        }

        // Archived entries do not count: retiring an old listing has to free a
        // slot, or the quota becomes a one-way ratchet.
        $used = $this->items->countOwnedBy(
            (int) $user->getId(),
            ShowcaseItem::STATUS_DRAFT,
            ShowcaseItem::STATUS_PENDING,
            ShowcaseItem::STATUS_PUBLISHED,
            ShowcaseItem::STATUS_REJECTED,
        );

        return ['limit' => $limit, 'used' => $used, 'exceeded' => $used >= $limit];
    }

    /**
     * Submission throttle. Without it a single account can fill the moderation
     * queue — and the storage — faster than anyone can review it.
     */
    private function assertNotFlooding(User $user): void
    {
        $limit = $this->config->submitRateLimit();

        if ($limit === 0) {
            return;
        }

        $identifier = 'showcase_submit_'.$user->getId();

        if (!$this->flood->isAllowed('showcase_submit', $identifier, $limit, self::SUBMIT_WINDOW)) {
            throw new TooManyRequestsHttpException(null, $this->translator->trans('showcase.member.error.rate_limited'));
        }
    }

    private function registerSubmission(User $user): void
    {
        if ($this->config->submitRateLimit() > 0) {
            $this->flood->register('showcase_submit', 'showcase_submit_'.$user->getId(), self::SUBMIT_WINDOW);
        }
    }

    private function typeFrom(mixed $raw): ?ShowcaseType
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $type = $this->types->findOneByMachineName($raw);

        return $type instanceof ShowcaseType && $type->isEnabled() ? $type : null;
    }

    /**
     * Loads an entry the current user is allowed to edit. The ownership decision
     * belongs to the voter, not to a manual comparison here.
     */
    private function findOwnedItem(int $id): ShowcaseItem
    {
        $item = $this->items->find($id);

        if (!$item instanceof ShowcaseItem || $item->isDeleted()) {
            throw new NotFoundHttpException($this->translator->trans('showcase.items.error.not_found'));
        }

        if (!$this->access->canEdit($item)) {
            throw $this->createAccessDeniedException();
        }

        return $item;
    }

    private function requireUser(): User
    {
        $user = $this->access->currentUser();

        if ($user === null) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }
    }
}
