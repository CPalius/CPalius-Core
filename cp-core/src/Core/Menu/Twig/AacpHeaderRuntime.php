<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use App\Entity\User;
use App\Repository\AssetRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * AACP header'daki profil dropdown'ının avatarını çözer. AACPUserController
 * ::profile() ile AYNI mantık ("avatarAssetId varsa AssetRepository'den
 * storageKey'i çek, yoksa null dön") burada tekrarlanmak yerine buraya
 * taşınmıştır ki layout.html.twig HER sayfada (profil ekranı olmasa bile)
 * doğru avatarı gösterebilsin.
 */
final class AacpHeaderRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function currentUserAvatarUrl(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || $user->getAvatarAssetId() === null) {
            return null;
        }

        $asset = $this->assetRepository->find($user->getAvatarAssetId());

        return $asset?->getStorageKey() !== null ? '/uploads/'.$asset->getStorageKey() : null;
    }
}
