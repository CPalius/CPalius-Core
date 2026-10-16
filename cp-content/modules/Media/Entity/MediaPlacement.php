<?php

declare(strict_types=1);

namespace Modules\Media\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Which folder an asset sits in. No row = root folder. One row per asset, so
 * moving is an upsert and an asset can never be in two folders at once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_media_placements')]
#[ORM\Index(columns: ['folder_id'], name: 'idx_media_placement_folder')]
class MediaPlacement
{
    #[ORM\Id]
    #[ORM\Column(name: 'asset_id', type: 'integer')]
    private int $assetId;

    #[ORM\Column(name: 'folder_id', type: 'integer')]
    private int $folderId;

    public function __construct(int $assetId, int $folderId)
    {
        $this->assetId = $assetId;
        $this->folderId = $folderId;
    }

    public function getAssetId(): int
    {
        return $this->assetId;
    }

    public function getFolderId(): int
    {
        return $this->folderId;
    }

    public function setFolderId(int $folderId): static
    {
        $this->folderId = $folderId;

        return $this;
    }
}
