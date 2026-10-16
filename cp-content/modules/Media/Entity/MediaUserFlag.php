<?php

declare(strict_types=1);

namespace Modules\Media\Entity;

use App\Core\Annotation\NotTrashable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user state on an asset: starred, and when it was last opened (the
 * "Recent" view). Personal bookkeeping — not worth keeping in the recycle bin.
 */
#[NotTrashable]
#[ORM\Entity]
#[ORM\Table(name: 'cp_media_user_flags')]
#[ORM\Index(columns: ['asset_id'], name: 'idx_media_flag_asset')]
class MediaUserFlag
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: 'integer')]
    private int $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'asset_id', type: 'integer')]
    private int $assetId;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $favorite = false;

    #[ORM\Column(name: 'last_opened_at', type: 'datetime', nullable: true)]
    private ?\DateTime $lastOpenedAt = null;

    public function __construct(int $userId, int $assetId)
    {
        $this->userId = $userId;
        $this->assetId = $assetId;
    }

    public function getAssetId(): int
    {
        return $this->assetId;
    }

    public function isFavorite(): bool
    {
        return $this->favorite;
    }

    public function setFavorite(bool $favorite): static
    {
        $this->favorite = $favorite;

        return $this;
    }

    public function getLastOpenedAt(): ?\DateTime
    {
        return $this->lastOpenedAt;
    }

    public function touch(): static
    {
        $this->lastOpenedAt = new \DateTime();

        return $this;
    }
}
