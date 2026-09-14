<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use App\Core\Media\AssetUrlGenerator;
use App\Entity\User;
use App\Repository\AssetRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Resolves the AACP header profile avatar (same logic as AACPUserController::profile()).
 * Shared so layout.html.twig shows the correct avatar on every page, not only on profile.
 */
final class AacpHeaderRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AssetRepository $assetRepository,
        private readonly AssetUrlGenerator $urls,
    ) {
    }

    public function currentUserAvatarUrl(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || $user->getAvatarAssetId() === null) {
            return null;
        }

        $asset = $this->assetRepository->find($user->getAvatarAssetId());

        return $asset?->getStorageKey() !== null ? $this->urls->forKey($asset->getStorageKey()) : null;
    }
}
