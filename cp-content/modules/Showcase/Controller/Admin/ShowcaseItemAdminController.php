<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Localization\LocaleProvider;
use App\Core\Notification\NotificationDispatcher;
use App\Core\Notification\NotificationSubject;
use App\Core\Pagination\Paginator;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Core\TextFormat\TextFormatAccess;
use App\Entity\User;
use Modules\Showcase\Dto\ShowcaseItemInput;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Query\ShowcaseFilter;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseReviewRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcaseConfig;
use Modules\Showcase\Service\ShowcaseFieldUploadHandler;
use Modules\Showcase\Service\ShowcaseItemManager;
use Modules\Showcase\Service\ShowcaseLinkService;
use Modules\Showcase\Service\ShowcaseMediaService;
use Modules\Showcase\Service\ShowcaseTypeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Moderation queue and editorial CRUD for showcase entries.
 *
 * Writes go through ShowcaseItemManager, the same service the public submission
 * form uses, so an entry edited by a moderator is sanitized and validated exactly
 * like one submitted by a member.
 */
#[Route('/admin/showcase/items', name: 'admin_showcase_items_')]
#[IsGranted('showcase.item.view.any')]
final class ShowcaseItemAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_showcase_item';
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseReviewRepository $reviews,
        private readonly ShowcaseItemManager $itemManager,
        private readonly ShowcaseTypeManager $typeManager,
        private readonly ShowcaseMediaService $media,
        private readonly ShowcaseConfig $config,
        private readonly ShowcaseLinkService $links,
        private readonly ShowcaseFieldUploadHandler $fieldUploads,
        private readonly TermRepository $terms,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly TextFormatAccess $textFormats,
        private readonly LocaleProvider $localeProvider,
        private readonly Paginator $paginator,
        private readonly NotificationDispatcher $notifications,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'showcase.menu.items', icon: 'heroicons:rectangle-group', panel: 'studio', priority: 32, capability: 'showcase.item.view.any', parent: 'admin_showcase_dashboard')]
    public function index(Request $request): Response
    {
        $locale = $this->localeProvider->resolve($request->query->get('locale'));
        $status = (string) $request->query->get('status', '');
        $type = $this->typeFromQuery($request->query->get('type'));

        $filter = new ShowcaseFilter(
            locale: $locale,
            type: $type,
            statuses: \in_array($status, ShowcaseItem::STATUSES, true) ? [$status] : ShowcaseItem::STATUSES,
            search: $this->searchTerm($request->query->get('q')),
            sort: ShowcaseFilter::normalizeSort($request->query->get('sort')),
            includeExpired: true,
            includeTrashed: $request->query->getBoolean('trashed'),
            anyLocale: $request->query->get('locale') === null,
        );

        $result = $this->paginator->paginate(
            $this->items->createFilteredQueryBuilder($filter),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('@ShowcaseModule/admin/items/index.html.twig', [
            'result' => $result,
            'counts' => $this->items->countByStatus(),
            'types' => $this->types->findAllOrdered(),
            'statuses' => ShowcaseItem::STATUSES,
            'currentStatus' => $status,
            'currentType' => $type?->getMachineName() ?? '',
            'currentLocale' => $request->query->get('locale'),
            'locales' => $this->localeProvider->getLocales(),
            'search' => (string) $request->query->get('q', ''),
            'trashed' => $request->query->getBoolean('trashed'),
            'pendingReviews' => $this->reviews->countPending(),
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    #[IsGranted('showcase.item.create')]
    public function create(Request $request): Response
    {
        $type = $this->typeFromQuery($request->isMethod('POST') ? $request->request->get('type') : $request->query->get('type'));

        if (!$type instanceof ShowcaseType) {
            $enabled = $this->types->findEnabled();

            if ($enabled === []) {
                $this->addFlash('error', $this->translator->trans('showcase.items.error.no_type'));

                return $this->redirectToRoute('admin_showcase_types_index');
            }

            return $this->redirectToRoute('admin_showcase_items_create', ['type' => $enabled[0]->getMachineName()]);
        }

        $locale = $this->localeProvider->resolve(
            $request->isMethod('POST') ? $request->request->get('locale') : $request->query->get('locale'),
        );

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            $result = $this->itemManager->create(
                $type,
                $this->currentUser(),
                $locale,
                $this->inputFrom($request, $type),
                canPublishDirectly: true,
            );

            if ($result['item'] === null) {
                return $this->renderForm(null, $type, $locale, $request, $result['errors']);
            }

            // Images travel with the create form; they can only be attached once
            // the entry has an id, so that happens here rather than forcing a
            // save-then-return-to-add-pictures round trip.
            $this->storeUploads($result['item'], $request);

            $this->addFlash('success', $this->translator->trans('showcase.items.flash.created', ['title' => $result['item']->getTitle()]));

            return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $result['item']->getId()]);
        }

        return $this->renderForm(null, $type, $locale, $request, []);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->denyAccessUnlessGranted('showcase.item.edit.any');

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            // A moderator editing someone else's entry must not silently push it
            // back into the queue, so the resubmit rule is switched off here.
            $errors = $this->itemManager->update(
                $item,
                $this->inputFrom($request, $item->getType()),
                canPublishDirectly: true,
                resubmit: false,
            );

            if ($errors !== []) {
                return $this->renderForm($item, $item->getType(), $item->getLocale(), $request, $errors);
            }

            $this->addFlash('success', $this->translator->trans('showcase.items.flash.updated', ['title' => $item->getTitle()]));

            return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
        }

        return $this->renderForm($item, $item->getType(), $item->getLocale(), $request, []);
    }

    #[Route('/{id}/moderate/{action}', name: 'moderate', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'approve|reject|archive|feature|unfeature'])]
    #[IsGranted('showcase.item.moderate')]
    public function moderate(int $id, string $action, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        switch ($action) {
            case 'approve':
                $this->itemManager->publish($item);
                $this->notifyOwner($item, 'showcase.item_approved');
                break;
            case 'reject':
                $reason = trim((string) $request->request->get('reason'));
                $this->itemManager->reject($item, $reason);
                $this->notifyOwner($item, 'showcase.item_rejected', ['reason' => $reason]);
                break;
            case 'archive':
                $this->itemManager->archive($item);
                break;
            case 'feature':
                $this->itemManager->setFeatured($item, true);
                break;
            case 'unfeature':
                $this->itemManager->setFeatured($item, false);
                break;
        }

        $this->addFlash('success', $this->translator->trans('showcase.items.flash.moderated'));

        return $this->redirectToReferer($request);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('showcase.item.delete.any')]
    public function delete(int $id, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        if ($request->request->getBoolean('purge')) {
            $this->itemManager->purgeItem($item);
            $this->addFlash('success', $this->translator->trans('showcase.items.flash.purged'));
        } else {
            $this->itemManager->softDelete($item);
            $this->addFlash('success', $this->translator->trans('showcase.items.flash.trashed'));
        }

        return $this->redirectToRoute('admin_showcase_items_index');
    }

    #[Route('/{id}/restore', name: 'restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('showcase.item.delete.any')]
    public function restore(int $id, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        $this->itemManager->restore($item);
        $this->addFlash('success', $this->translator->trans('showcase.items.flash.restored'));

        return $this->redirectToReferer($request);
    }

    #[Route('/{id}/translate/{locale}', name: 'translate', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[a-z]{2}(_[A-Z]{2})?'])]
    #[IsGranted('showcase.item.edit.any')]
    public function translate(int $id, string $locale, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        if (!$this->localeProvider->isSupported($locale)) {
            throw new BadRequestHttpException('Unsupported locale.');
        }

        $translation = $this->itemManager->createTranslation($item, $locale);

        if ($translation === null) {
            $this->addFlash('error', $this->translator->trans('showcase.items.error.translation_exists'));

            return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
        }

        $this->addFlash('success', $this->translator->trans('showcase.items.flash.translation_created', ['locale' => $locale]));

        return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $translation->getId()]);
    }

    #[Route('/{id}/media', name: 'media_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('showcase.item.edit.any')]
    public function addMedia(int $id, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        $this->storeUploads($item, $request);

        return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
    }

    /**
     * Attaches whatever came in on "images[]", reporting per-file problems.
     * Shared by the create form and the gallery box so both behave identically.
     */
    private function storeUploads(ShowcaseItem $item, Request $request): void
    {
        // A crafted post can send "images" as a scalar; the guard keeps that from
        // reaching array_values(). ShowcaseMediaService skips anything that is
        // not an UploadedFile anyway.
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
    #[IsGranted('showcase.item.edit.any')]
    public function deleteMedia(int $id, int $mediaId, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        $this->media->remove($item, $mediaId);

        return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
    }

    #[Route('/{id}/media/{mediaId}/cover', name: 'media_cover', methods: ['POST'], requirements: ['id' => '\d+', 'mediaId' => '\d+'])]
    #[IsGranted('showcase.item.edit.any')]
    public function setCover(int $id, int $mediaId, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        foreach ($item->getMedia() as $media) {
            if ($media->getId() === $mediaId) {
                $this->media->setCover($item, $media->getAssetId());
                break;
            }
        }

        return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
    }

    #[Route('/{id}/links', name: 'link_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('showcase.item.edit.any')]
    public function attachLink(int $id, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
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

        return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
    }

    #[Route('/{id}/links/{linkId}/delete', name: 'link_delete', methods: ['POST'], requirements: ['id' => '\d+', 'linkId' => '\d+'])]
    #[IsGranted('showcase.item.edit.any')]
    public function detachLink(int $id, int $linkId, Request $request): Response
    {
        $item = $this->findItemOrFail($id);
        $this->assertCsrf($request);

        foreach ($item->getLinks() as $link) {
            if ($link->getId() === $linkId) {
                $this->links->detach($item, $link);
                break;
            }
        }

        return $this->redirectToRoute('admin_showcase_items_edit', ['id' => $id]);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function renderForm(?ShowcaseItem $item, ShowcaseType $type, string $locale, Request $request, array $errors): Response
    {
        $vocabulary = $this->typeManager->vocabularyOf($type);

        return $this->render('@ShowcaseModule/admin/items/form.html.twig', [
            'item' => $item,
            'type' => $type,
            'types' => $this->types->findEnabled(),
            'locale' => $locale,
            'locales' => $this->localeProvider->getLocales(),
            'fields' => $this->fieldDefinitions->getFieldsForBundle($type->fieldBundle()),
            'fieldValues' => $this->fieldValues($item, $request),
            'errors' => $errors,
            'priceModes' => ShowcaseItem::PRICE_MODES,
            'formatChoices' => $this->textFormats->usableChoices(),
            'categoryTerms' => $vocabulary !== null ? $this->terms->findByVocabulary($vocabulary, $locale) : [],
            'selectedTerms' => $this->selectedTermIds($item, $request),
            'gallery' => $item !== null ? $this->media->gallery($item) : [],
            'galleryLimit' => $this->config->maxGalleryImages(),
            'resolvedLinks' => $item !== null ? $this->links->resolveAll($item, $locale) : [],
            'siblings' => $item !== null ? $this->items->findTranslationSiblings($item) : [],
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
     * @return array<string, mixed>
     */
    private function fieldValues(?ShowcaseItem $item, Request $request): array
    {
        if ($request->isMethod('POST')) {
            return $request->request->all('fields');
        }

        return $item?->getFieldableData() ?? [];
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
     * @param array<string, mixed> $payload
     */
    private function notifyOwner(ShowcaseItem $item, string $eventKey, array $payload = []): void
    {
        $owner = $item->getOwner();

        if (!$owner instanceof User) {
            return;
        }

        try {
            $this->notifications->dispatch(
                $eventKey,
                $owner,
                $payload + ['title' => $item->getTitle(), 'slug' => $item->getSlug()],
                $this->currentUser(),
                new NotificationSubject(ShowcaseItem::ENTITY_TYPE_ID, $item->getId()),
            );
        } catch (\Throwable) {
            // A moderation decision must land even when the notification layer
            // is misconfigured; the dispatcher already logs its own failures.
        }
    }

    private function typeFromQuery(mixed $raw): ?ShowcaseType
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        return $this->types->findOneByMachineName($raw);
    }

    private function searchTerm(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        $term = trim(strip_tags($raw));

        return mb_strlen($term) >= 2 ? mb_substr($term, 0, 100) : null;
    }

    private function findItemOrFail(int $id): ShowcaseItem
    {
        $item = $this->items->find($id);

        if (!$item instanceof ShowcaseItem) {
            throw new NotFoundHttpException($this->translator->trans('showcase.items.error.not_found'));
        }

        return $item;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function redirectToReferer(Request $request): Response
    {
        // Only same-origin referers are honoured, so a crafted Referer header
        // cannot turn a moderation action into an open redirect.
        $referer = (string) $request->headers->get('referer', '');

        if ($referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost().'/admin/showcase')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin_showcase_items_index');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }
    }
}
