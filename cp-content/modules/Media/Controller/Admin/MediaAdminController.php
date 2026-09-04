<?php

namespace Modules\Media\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Media\AssetManager;
use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Media\MimeTypeAllowlist;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
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
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly MimeTypeAllowlist $mimeTypeAllowlist,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Medya', icon: 'heroicons:photo', panel: 'studio', priority: 25, capability: 'media.view', group: 'İçerik')]
    #[IsGranted('media.view')]
    public function index(Request $request): Response
    {
        return $this->render('@MediaModule/admin/media/index.html.twig', [
            'assets' => $this->searchAssets($request),
            'query' => (string) $request->query->get('q', ''),
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
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.no_file'));
        }

        try {
            $asset = $this->assetManager->upload($uploadedFile);
        } catch (UnsupportedAssetTypeException $e) {
            // Wrong file type is a client error (400), not 500.
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.unsupported_type', [
                'mimeType' => $e->detectedMimeType,
                'allowed' => implode(', ', $this->mimeTypeAllowlist->allowedExtensions()),
            ]), $e);
        } catch (InvalidUploadException $e) {
            // Upload did not arrive intact (PHP/temp/content) — still 400 for the client.
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.upload_failed'), $e);
        }

        return new JsonResponse($this->assetToArray($asset));
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

        if ($this->cpaliusStorage->fileExists($asset->getStorageKey())) {
            $this->cpaliusStorage->delete($asset->getStorageKey());
        }

        $this->entityManager->remove($asset);
        $this->entityManager->flush();

        // No Asset↔Node FK (see Asset); usage checks are out of scope (YAGNI). Show a warning only.
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
