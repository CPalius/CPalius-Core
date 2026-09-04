<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Media\AssetManager;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Kullanıcı avatarı yükleme ve URL çözümleme — media.view/upload gerektirmez.
 * AssetManager güvenlik sınırını kullanır; yalnızca görsel MIME kabul edilir.
 */
final class UserAvatarService
{
    /** @var list<string> */
    private const IMAGE_MIME_PREFIX = 'image/';

    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly AssetRepository $assetRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveUrl(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $assetId = $user->getAvatarAssetId();
        if ($assetId === null) {
            return null;
        }

        $asset = $this->assetRepository->find($assetId);
        if (!$asset instanceof Asset) {
            return null;
        }

        $key = $asset->getStorageKey();
        if ($key === null || $key === '') {
            return null;
        }

        if (!$this->isImageMime($asset->getMimeType())) {
            return null;
        }

        return '/uploads/'.$key;
    }

    public function upload(User $user, UploadedFile $file): Asset
    {
        $asset = $this->assetManager->upload($file);

        if (!$this->isImageMime($asset->getMimeType())) {
            throw new UnsupportedAssetTypeException($asset->getMimeType());
        }

        $user->setAvatarAssetId($asset->getId());
        $this->entityManager->flush();

        return $asset;
    }

    public function remove(User $user): void
    {
        $user->setAvatarAssetId(null);
        $this->entityManager->flush();
    }

    private function isImageMime(string $mimeType): bool
    {
        return str_starts_with(strtolower($mimeType), self::IMAGE_MIME_PREFIX)
            && !str_contains(strtolower($mimeType), 'svg');
    }
}
