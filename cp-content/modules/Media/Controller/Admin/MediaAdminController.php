<?php

declare(strict_types=1);

namespace Modules\Media\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Media\AssetManager;
use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Media\MimeTypeAllowlist;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use Modules\Media\Service\MediaLibraryService;
use Modules\Media\Service\MediaUploadSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio gallery, upload, and picker on top of core Asset/AssetManager (Law 2 — Core Never Dies).
 */
#[Route('/admin/media', name: 'admin_media_')]
final class MediaAdminController extends AbstractController
{
    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly AssetRepository $assetRepository,
        private readonly TranslatorInterface $translator,
        private readonly MediaLibraryService $library,
        private readonly MediaUploadSettings $uploadSettings,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'media.index.header', icon: 'heroicons:photo', panel: 'studio', priority: 25, capability: 'media.view', group: 'studio.group.content')]
    #[IsGranted('media.view')]
    public function index(Request $request): Response
    {
        $allowlist = $this->uploadSettings->allowlist();

        return $this->render('@MediaModule/admin/media/index.html.twig', [
            'canUpload' => $this->isGranted('media.upload'),
            'canDelete' => $this->isGranted('media.delete'),
            'canSettings' => $this->isGranted('media.settings.manage'),
            'perPage' => MediaLibraryService::PER_PAGE,
            'maxBytes' => $this->uploadSettings->maxBytes(),
            'extensions' => $allowlist->allowedExtensions(),
            'mimeTypes' => $allowlist->allowedMimeTypes(),
        ]);
    }

    /**
     * Which file types and how large: the library's upload policy.
     */
    #[Route('/settings', name: 'settings', methods: ['GET', 'POST'])]
    #[IsGranted('media.settings.manage')]
    public function settings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_media_settings', (string) $request->request->get('_token'))) {
                throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
            }

            $extensions = array_map('strval', $request->request->all('extensions'));
            if ($extensions === []) {
                $this->addFlash('error', $this->translator->trans('media.settings.flash.none'));

                return $this->redirectToRoute('admin_media_settings');
            }

            $this->uploadSettings->save($extensions, $request->request->getInt('max_mb'));
            $this->addFlash('success', $this->translator->trans('media.settings.flash.saved'));

            return $this->redirectToRoute('admin_media_settings');
        }

        return $this->render('@MediaModule/admin/media/settings.html.twig', [
            'catalog' => MimeTypeAllowlist::catalog(),
            'enabled' => $this->uploadSettings->allowedExtensions(),
            'maxMb' => $this->uploadSettings->maxMegabytes(),
            'serverLabel' => $this->limitLabel(MediaUploadSettings::serverLimitBytes()),
            'effectiveLabel' => $this->limitLabel($this->uploadSettings->maxBytes()),
        ]);
    }

    /**
     * Upload endpoint. MIME checks live in AssetManager::upload() + MimeTypeAllowlist (Law 5).
     * This action only maps core exceptions to a localized HTTP 400.
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[IsGranted('media.upload')]
    public function upload(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        if ($uploadedFile === null) {
            // The body is dropped wholesale when it exceeds post_max_size.
            throw new BadRequestHttpException($this->translator->trans($request->server->getInt('CONTENT_LENGTH') > 0 ? 'media.admin.error.too_large_server' : 'media.admin.error.no_file'));
        }

        $folderId = $request->request->getInt('folder') ?: null;
        $allowlist = $this->uploadSettings->allowlist();
        $maxBytes = $this->uploadSettings->maxBytes();

        if (!$uploadedFile->isValid() && \in_array($uploadedFile->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.too_large_server'));
        }
        if ($maxBytes > 0 && (int) $uploadedFile->getSize() > $maxBytes) {
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.too_large', ['limit' => $this->megabytes($maxBytes)]));
        }

        try {
            // AssetManager dedups by content hash and hands back the existing row.
            // Note that before the upload: a file already in another folder must
            // not be yanked out of it, it gets its own row in the new folder.
            $hash = hash_file('sha256', $uploadedFile->getPathname());
            $existing = $hash !== false ? $this->assetRepository->findOneByHash($hash) : null;

            $asset = $this->assetManager->upload($uploadedFile, null, $maxBytes > 0 ? $maxBytes : null, $allowlist);

            if ($folderId !== null) {
                $asset = $this->library->placeUpload($asset, $existing !== null, $folderId, $uploadedFile->getClientOriginalName());
            }
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($this->translator->trans($e->getMessage()), $e);
        } catch (UnsupportedAssetTypeException $e) {
            // Wrong file type is a client error (400), not 500.
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.unsupported_type', ['mimeType' => $e->detectedMimeType, 'allowed' => implode(', ', $allowlist->allowedExtensions())]), $e);
        } catch (InvalidUploadException $e) {
            // Upload did not arrive intact (PHP/temp/content) — still 400 for the client.
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.upload_failed'), $e);
        }

        $user = $this->getUser();

        return new JsonResponse($this->assetToArray($asset) + [
            // The library screen drops this straight into its list.
            'asset' => $this->library->presentAssets([$asset], $user instanceof User ? $user->getId() : null)[0],
        ]);
    }

    private function limitLabel(int $bytes): string
    {
        return $bytes > 0 ? $this->megabytes($bytes).' MB' : '∞';
    }

    private function megabytes(int $bytes): string
    {
        return (string) round($bytes / 1048576, $bytes < 10485760 ? 1 : 0);
    }

    #[Route('/picker', name: 'picker', methods: ['GET'])]
    #[IsGranted('media.view')]
    public function picker(Request $request): Response
    {
        // Standalone picker fragment: do not extend admin/layout (injected into a dialog).
        return $this->render('@MediaModule/admin/media/picker.html.twig', [
            'assets' => $this->searchAssets($request),
            'query' => (string) $request->query->get('q', ''),
            'canUpload' => $this->isGranted('media.upload'),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.delete')]
    public function delete(int $id, Request $request): Response
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_media_delete', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $asset = $this->assetRepository->find($id);
        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException($this->translator->trans('media.admin.error.not_found'));
        }

        // Removes the row only: the recycle bin keeps it restorable, and
        // AssetTrashPurgeHandler deletes the file (and its offloaded copy) once
        // the bin lets go of the row for good.
        $this->library->deleteAssets([$id]);

        $this->addFlash('success', $this->translator->trans('media.admin.flash.deleted', ['name' => $asset->getOriginalName()]));

        return $this->redirectToRoute('admin_media_index');
    }

    /**
     * @return list<Asset>
     */
    private function searchAssets(Request $request): array
    {
        $qb = $this->assetRepository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(200);

        $query = trim((string) $request->query->get('q', ''));
        if ($query !== '') {
            $qb->andWhere('a.originalName LIKE :q')->setParameter('q', '%'.$query.'%');
        }

        $type = (string) $request->query->get('type', '');
        if ($type !== '') {
            $qb->andWhere('a.mimeType LIKE :type')->setParameter('type', $type.'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{id: int, url: string, originalName: string, mimeType: string}
     */
    private function assetToArray(Asset $asset): array
    {
        return [
            'id' => $asset->getId(),
            'url' => '/uploads/'.$asset->getStorageKey(),
            'originalName' => $asset->getOriginalName(),
            'mimeType' => $asset->getMimeType(),
        ];
    }
}
