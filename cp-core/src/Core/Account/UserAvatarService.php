<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Media\AssetManager;
use App\Core\Media\AssetUrlGenerator;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads user avatars and resolves URLs without media.view/upload.
 * Uses AssetManager security boundaries; only non-SVG image MIME types are accepted.
 */
final class UserAvatarService
{
    private const IMAGE_MIME_PREFIX = 'image/';

    /** Default ceiling in KiB; the operator can lower it via account.avatar_max_kb. */
    private const DEFAULT_MAX_KB = 1024;

    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly AssetRepository $assetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsRegistry $settings,
        private readonly AssetUrlGenerator $urls,
    ) {
    }

    /**
     * Public URL for an avatar that was just uploaded, before the caller has a
     * User to re-resolve from. Same CDN treatment as resolveUrl(), in one place
     * so the two can never disagree about where avatars are served from.
     */
    public function urlForAsset(Asset $asset): string
    {
        return $this->urls->forKey($asset->getStorageKey());
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

        return $this->urls->forKey($key);
    }

    /**
     * MIME and size are checked in AssetManager before write; a rejected avatar must never land on disk.
     */
    public function upload(User $user, UploadedFile $file): Asset
    {
        $asset = $this->assetManager->upload($file, [self::IMAGE_MIME_PREFIX], $this->maxBytes());

        // Dedup may return an existing Asset; resolveUrl() trusts this MIME.
        if (!$this->isImageMime($asset->getMimeType())) {
            throw new UnsupportedAssetTypeException($asset->getMimeType());
        }

        $user->setAvatarAssetId($asset->getId());
        $this->entityManager->flush();

        return $asset;
    }

    private function maxBytes(): int
    {
        $kb = (int) $this->settings->get('account.avatar_max_kb', self::DEFAULT_MAX_KB);

        return max(1, min(self::DEFAULT_MAX_KB * 8, $kb)) * 1024;
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
