<?php

declare(strict_types=1);

namespace Modules\Media\Controller\Admin;

use App\Core\Media\AssetUrlGenerator;
use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\Repository\TrashItemRepository;
use App\Core\Trash\TrashManager;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use League\Flysystem\FilesystemOperator;
use Modules\Media\Service\MediaLibraryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * JSON API behind the Studio media library screen.
 *
 * Reads need media.view; anything that organises or edits needs media.upload;
 * anything that removes needs media.delete. Every POST carries the page's CSRF
 * token in X-CSRF-Token — these are fetch() calls, not forms.
 */
#[Route('/admin/media/api', name: 'admin_media_api_')]
final class MediaLibraryApiController extends AbstractController
{
    public const CSRF = 'admin_media_api';

    private const ZIP_MAX_FILES = 200;
    private const ZIP_MAX_BYTES = 300 * 1024 * 1024;
    private const TRASH_TYPES = ['Asset', 'MediaPlacement', 'MediaFolder'];

    public function __construct(
        private readonly MediaLibraryService $library,
        private readonly TrashManager $trash,
        private readonly TrashItemRepository $trashItems,
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly TranslatorInterface $translator,
        private readonly AssetRepository $assetRepository,
        private readonly AssetUrlGenerator $assetUrls,
    ) {
    }

    #[Route('/browse', name: 'browse', methods: ['GET'])]
    #[IsGranted('media.view')]
    public function browse(Request $request): JsonResponse
    {
        return $this->guard(fn (): array => $this->library->browse([
            'folder' => $this->intOrNull($request->query->get('folder')),
            'q' => (string) $request->query->get('q', ''),
            'type' => (string) $request->query->get('type', ''),
            'filter' => (string) $request->query->get('filter', 'all'),
            'sort' => (string) $request->query->get('sort', 'date_desc'),
            'page' => $request->query->getInt('page', 1),
        ], $this->userId()));
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    #[IsGranted('media.view')]
    public function tree(): JsonResponse
    {
        return $this->guard(fn (): array => ['folders' => $this->library->tree()]);
    }

    #[Route('/folders', name: 'folder_create', methods: ['POST'])]
    #[IsGranted('media.upload')]
    public function createFolder(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in): array {
            $folder = $this->library->createFolder((string) ($in['name'] ?? ''), $this->intOrNull($in['parent'] ?? null), $this->userId(), isset($in['color']) ? (string) $in['color'] : null);

            return ['id' => $folder->getId()];
        });
    }

    #[Route('/folders/{id}/rename', name: 'folder_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.upload')]
    public function renameFolder(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in) use ($id): array {
            $this->library->renameFolder($id, (string) ($in['name'] ?? ''));

            return [];
        });
    }

    #[Route('/folders/{id}/move', name: 'folder_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.upload')]
    public function moveFolder(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in) use ($id): array {
            $this->library->moveFolder($id, $this->intOrNull($in['parent'] ?? null));

            return [];
        });
    }

    #[Route('/folders/{id}/delete', name: 'folder_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.delete')]
    public function deleteFolder(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (): array => $this->library->deleteFolder($id));
    }

    #[Route('/assets/move', name: 'asset_move', methods: ['POST'])]
    #[IsGranted('media.upload')]
    public function moveAssets(Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $in): array => ['moved' => $this->library->moveAssets($this->ids($in), $this->intOrNull($in['folder'] ?? null))]);
    }

    #[Route('/assets/delete', name: 'asset_delete', methods: ['POST'])]
    #[IsGranted('media.delete')]
    public function deleteAssets(Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $in): array => ['deleted' => $this->library->deleteAssets($this->ids($in))]);
    }

    #[Route('/assets/favorite', name: 'asset_favorite', methods: ['POST'])]
    #[IsGranted('media.view')]
    public function favorite(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in): array {
            $userId = $this->userId();
            if ($userId !== null) {
                $this->library->setFavorite($userId, $this->ids($in), (bool) ($in['value'] ?? true));
            }

            return [];
        });
    }

    #[Route('/assets/{id}/rename', name: 'asset_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.upload')]
    public function renameAsset(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in) use ($id): array {
            $this->library->renameAsset($id, (string) ($in['name'] ?? ''));

            return [];
        });
    }

    #[Route('/assets/{id}/alt', name: 'asset_alt', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.upload')]
    public function alt(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in) use ($id): array {
            $this->library->setAlt($id, (string) ($in['alt'] ?? ''));

            return [];
        });
    }

    #[Route('/assets/{id}/copy', name: 'asset_copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.upload')]
    public function copy(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (): array => ['id' => $this->library->copyAsset($id)->getId()]);
    }

    #[Route('/assets/{id}/opened', name: 'asset_opened', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.view')]
    public function opened(int $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function () use ($id): array {
            $userId = $this->userId();
            if ($userId !== null) {
                $this->library->markOpened($userId, $id);
            }

            return [];
        });
    }

    /** Small lookup the field widgets use to preview a stored asset id. */
    #[Route('/assets/{id}/meta', name: 'asset_meta', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.view')]
    public function meta(int $id): JsonResponse
    {
        $asset = $this->assetRepository->find($id);
        if (!$asset instanceof Asset) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        return new JsonResponse([
            'id' => $asset->getId(),
            'url' => $this->assetUrls->forKey($asset->getStorageKey()),
            'name' => $asset->getOriginalName(),
            'mime' => $asset->getMimeType(),
        ]);
    }

    #[Route('/assets/{id}/usage', name: 'asset_usage', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('media.view')]
    public function usage(int $id): JsonResponse
    {
        return $this->guard(fn (): array => ['usage' => $this->library->usage($id)]);
    }

    #[Route('/assets/download', name: 'asset_download', methods: ['POST'])]
    #[IsGranted('media.view')]
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->assertCsrf($request, fromForm: true);

        $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('ids'))));
        if ($ids === [] || \count($ids) > self::ZIP_MAX_FILES || !class_exists(\ZipArchive::class)) {
            return new JsonResponse(['error' => $this->translator->trans('media.lib.error.download')], 400);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cpmedia');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return new JsonResponse(['error' => $this->translator->trans('media.lib.error.download')], 500);
        }

        $total = 0;
        $used = [];
        foreach ($ids as $id) {
            try {
                $asset = $this->library->asset($id);
            } catch (\DomainException) {
                continue;
            }
            $total += $asset->getFileSize();
            if ($total > self::ZIP_MAX_BYTES || !$this->cpaliusStorage->fileExists($asset->getStorageKey())) {
                continue;
            }
            $zip->addFromString($this->uniqueName($asset, $used), $this->cpaliusStorage->read($asset->getStorageKey()));
        }
        $zip->close();

        $response = new BinaryFileResponse($tmp, 200, [], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'media-'.date('Ymd-His').'.zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/trash', name: 'trash', methods: ['GET'])]
    #[IsGranted('media.delete')]
    public function trash(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): array {
            $items = $this->trashItems->createFilteredQueryBuilder(['type' => 'Asset'])
                ->setFirstResult(max(0, $request->query->getInt('page', 1) - 1) * MediaLibraryService::PER_PAGE)
                ->setMaxResults(MediaLibraryService::PER_PAGE)
                ->getQuery()
                ->getResult();

            return ['items' => array_map(static fn (TrashItem $t): array => [
                'id' => $t->getId(),
                'name' => $t->getLabel(),
                'mime' => (string) ($t->getRowData()['mime_type'] ?? ''),
                'size' => (int) ($t->getRowData()['file_size'] ?? 0),
                'deletedAt' => $t->getDeletedAt()->format(\DATE_ATOM),
                'expiresAt' => $t->getExpiresAt()?->format(\DATE_ATOM),
            ], $items)];
        });
    }

    #[Route('/trash/restore', name: 'trash_restore', methods: ['POST'])]
    #[IsGranted('media.delete')]
    public function restore(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in): array {
            $restored = 0;
            $errors = [];
            $seen = [];

            foreach ($this->ids($in) as $id) {
                $item = $this->trashItems->find($id);
                // The asset and the folder placement it sat in were deleted in
                // the same flush; restoring the batch puts both back.
                if (!$item instanceof TrashItem || isset($seen[$item->getBatchId()])) {
                    continue;
                }
                $seen[$item->getBatchId()] = true;
                $result = $this->trash->restoreBatch($item->getBatchId());
                $restored += $result['restored'];
                foreach ($result['failed'] as $failure) {
                    $errors[] = $failure['item']->getLabel().': '.$failure['error'];
                }
            }

            return ['restored' => $restored, 'errors' => $errors];
        });
    }

    #[Route('/trash/delete', name: 'trash_delete', methods: ['POST'])]
    #[IsGranted('media.delete')]
    public function deleteForever(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $in): array {
            $removed = 0;

            foreach ($this->ids($in) as $id) {
                $item = $this->trashItems->find($id);
                if (!$item instanceof TrashItem) {
                    continue;
                }
                foreach ($this->trashItems->findBatch($item->getBatchId()) as $member) {
                    if (\in_array($member->getResourceName(), self::TRASH_TYPES, true)) {
                        $this->trash->delete($member);
                        ++$removed;
                    }
                }
            }

            return ['removed' => $removed];
        });
    }

    #[Route('/trash/empty', name: 'trash_empty', methods: ['POST'])]
    #[IsGranted('media.delete')]
    public function emptyTrash(Request $request): JsonResponse
    {
        return $this->mutate($request, function (): array {
            $removed = 0;
            foreach (self::TRASH_TYPES as $type) {
                $removed += $this->trash->emptyBin($type);
            }

            return ['removed' => $removed];
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $action
     */
    private function mutate(Request $request, callable $action): JsonResponse
    {
        return $this->guard(function () use ($request, $action): array {
            $this->assertCsrf($request);
            $body = json_decode($request->getContent(), true);

            return $action(\is_array($body) ? $body : []);
        });
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return new JsonResponse($action());
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], 400);
        }
    }

    private function assertCsrf(Request $request, bool $fromForm = false): void
    {
        $token = $fromForm ? (string) $request->request->get('_token') : (string) $request->headers->get('X-CSRF-Token');

        if (!$this->isCsrfTokenValid(self::CSRF, $token)) {
            throw new \DomainException('aacp.common.error.invalid_csrf');
        }
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return list<int>
     */
    private function ids(array $in): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])), static fn (int $i): bool => $i > 0)));
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function userId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    /**
     * @param array<string, true> $used
     */
    private function uniqueName(Asset $asset, array &$used): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '_', $asset->getOriginalName()) ?: 'file';
        $candidate = $name;

        for ($i = 2; isset($used[$candidate]); ++$i) {
            $ext = pathinfo($name, \PATHINFO_EXTENSION);
            $candidate = ($ext !== '' ? substr($name, 0, -(\strlen($ext) + 1)) : $name).' ('.$i.')'.($ext !== '' ? '.'.$ext : '');
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
