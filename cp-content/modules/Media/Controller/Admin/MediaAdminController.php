<?php

namespace Modules\Media\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Media\AssetManager;
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
 * Asset (cp-core/src/Entity/Asset.php) ve AssetManager (cp-core/src/Core/
 * Media/AssetManager.php) çekirdeğe ait, paylaşılan temel altyapıdır — bu
 * controller SADECE onların üzerine bir yönetim arayüzü (galeri listesi,
 * yükleme ucu, diğer formlarda kullanılacak seçici modal) sağlar. Modül
 * devre dışı bırakılırsa Asset/AssetManager'a bağımlı hiçbir çekirdek
 * işlev bozulmaz (bkz. Manifesto Law 2 — Core Never Dies).
 */
#[Route('/admin/media', name: 'admin_media_')]
final class MediaAdminController extends AbstractController
{
    private const ALLOWED_MIME_PREFIXES = ['image/', 'application/pdf', 'video/'];

    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly AssetRepository $assetRepository,
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
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

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[IsGranted('media.upload')]
    public function upload(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        if ($uploadedFile === null) {
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.no_file'));
        }

        // Manifesto Law 5.3: MIME tipi uzantı değil, dosya içeriği (finfo) ile doğrulanır.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($uploadedFile->getPathname());

        $isAllowed = false;
        foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($detectedMime, $prefix)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            throw new BadRequestHttpException($this->translator->trans('media.admin.error.unsupported_type', ['mimeType' => $detectedMime]));
        }

        $asset = $this->assetManager->upload($uploadedFile);

        return new JsonResponse($this->assetToArray($asset));
    }

    #[Route('/picker', name: 'picker', methods: ['GET'])]
    #[IsGranted('media.view')]
    public function picker(Request $request): Response
    {
        // Bilinçli olarak admin/layout.html.twig extend ETMEZ: bu partial bir
        // modal/iframe içeriği olarak açılır, Studio kabuğunu tekrar render
        // etmesi hem gereksiz hem de modal deneyimini bozar.
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

        // Asset<->Node ilişkisi bilinçli olarak yok (bkz. Asset entity
        // doc-block'u) — bu yüzden "kullanımda mı" kontrolü node_field_index
        // üzerinden yapılamaz. Sert bir referans kontrolü kapsam dışı (YAGNI);
        // kullanıcı bilgilendirici bir uyarı görür.
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
