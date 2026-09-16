<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Media\AssetManager;
use App\Core\Media\Exception\AssetUploadException;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseItemMedia;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gallery handling for showcase items.
 *
 * Uploads are delegated to the core AssetManager and nowhere else: it is the one
 * gateway that checks the real MIME with finfo instead of trusting the filename,
 * hashes the content, and writes into the non-executable storage (Law 5.3). This
 * service only decides how many images an item may hold and in what order.
 */
final class ShowcaseMediaService
{
    private const IMAGE_PREFIXES = ['image/'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetManager $assetManager,
        private readonly AssetRepository $assets,
        private readonly ShowcaseConfig $config,
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return array{added: int, errors: list<string>}
     */
    public function addUploads(ShowcaseItem $item, array $files): array
    {
        $limit = $this->config->maxGalleryImages();
        $current = $item->getMedia()->count();
        $added = 0;
        $errors = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            if ($current + $added >= $limit) {
                $errors[] = 'showcase.media.error.limit_reached';
                break;
            }

            try {
                $asset = $this->assetManager->upload($file, self::IMAGE_PREFIXES, $this->config->maxImageBytes());
            } catch (AssetUploadException) {
                // The core exceptions carry storage paths and MIME details; the
                // visitor gets a translated message and the detail stays server-side.
                $errors[] = 'showcase.media.error.upload_failed';
                continue;
            }

            $assetId = $asset->getId();

            if ($assetId === null || $this->hasAsset($item, $assetId)) {
                // Content-hash dedup means re-uploading the same picture returns
                // the existing asset; attaching it twice would violate the unique key.
                continue;
            }

            $media = new ShowcaseItemMedia($item, $assetId);
            $media->setWeight(($current + $added) * 10);

            $item->addMedia($media);
            $this->entityManager->persist($media);
            ++$added;
        }

        if ($added > 0) {
            $this->entityManager->flush();
            $this->ensureCover($item);
        }

        return ['added' => $added, 'errors' => $errors];
    }

    public function remove(ShowcaseItem $item, int $mediaId): bool
    {
        foreach ($item->getMedia() as $media) {
            if ($media->getId() !== $mediaId) {
                continue;
            }

            $wasCover = $item->getCoverAssetId() === $media->getAssetId();

            $item->removeMedia($media);
            $this->entityManager->remove($media);
            $this->entityManager->flush();

            if ($wasCover) {
                $item->setCoverAssetId(null);
                $this->ensureCover($item);
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<int> $orderedMediaIds
     */
    public function reorder(ShowcaseItem $item, array $orderedMediaIds): void
    {
        $positions = array_flip($orderedMediaIds);
        $touched = false;

        foreach ($item->getMedia() as $media) {
            $id = $media->getId();

            if ($id === null || !isset($positions[$id])) {
                continue;
            }

            $media->setWeight(((int) $positions[$id]) * 10);
            $touched = true;
        }

        if ($touched) {
            $this->entityManager->flush();
        }
    }

    public function setCover(ShowcaseItem $item, int $assetId): bool
    {
        if (!$this->hasAsset($item, $assetId)) {
            return false;
        }

        $item->setCoverAssetId($assetId);
        $this->entityManager->flush();

        return true;
    }

    /**
     * URLs for the gallery, skipping assets that vanished from the library.
     *
     * @return list<array{id: int, assetId: int, caption: ?string}>
     */
    public function gallery(ShowcaseItem $item): array
    {
        $assetIds = [];

        foreach ($item->getMedia() as $media) {
            $assetIds[$media->getAssetId()] = $media->getAssetId();
        }

        if ($assetIds === []) {
            return [];
        }

        // One query for the whole gallery rather than one per slide. Beyond
        // saving queries, this hydrates the assets into Doctrine's identity map,
        // so the cp_thumb call on each slide resolves without going back to the
        // database — which is what would otherwise trip the N+1 guard on an item
        // with a dozen images.
        $present = [];

        foreach ($this->assets->findBy(['id' => array_values($assetIds)]) as $asset) {
            $id = $asset->getId();

            if ($id !== null) {
                $present[$id] = true;
            }
        }

        $out = [];

        foreach ($item->getMedia() as $media) {
            if (!isset($present[$media->getAssetId()])) {
                continue;
            }

            $out[] = [
                'id' => (int) $media->getId(),
                'assetId' => $media->getAssetId(),
                'caption' => $media->getCaption(),
            ];
        }

        return $out;
    }

    /**
     * An item with pictures but no cover shows nothing in listings, which reads as
     * a broken entry. The first image becomes the cover until the owner picks one.
     */
    private function ensureCover(ShowcaseItem $item): void
    {
        if ($item->getCoverAssetId() !== null) {
            return;
        }

        foreach ($item->getMedia() as $media) {
            $item->setCoverAssetId($media->getAssetId());
            $this->entityManager->flush();

            return;
        }
    }

    private function hasAsset(ShowcaseItem $item, int $assetId): bool
    {
        foreach ($item->getMedia() as $media) {
            if ($media->getAssetId() === $assetId) {
                return true;
            }
        }

        return false;
    }
}
