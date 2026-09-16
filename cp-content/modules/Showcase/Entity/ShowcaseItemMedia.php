<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One gallery slot on an item. Only the Media asset id is stored — the bytes,
 * the MIME check and the storage backend all stay the core AssetManager's job
 * (Law 5.3: uploads are never handled by a module).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_showcase_item_media')]
#[ORM\UniqueConstraint(name: 'uniq_showcase_media_item_asset', columns: ['item_id', 'asset_id'])]
#[ORM\Index(columns: ['item_id', 'weight'], name: 'idx_showcase_media_item_weight')]
class ShowcaseItemMedia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShowcaseItem::class, inversedBy: 'media')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShowcaseItem $item;

    #[ORM\Column(name: 'asset_id', type: 'integer')]
    private int $assetId;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    public function __construct(ShowcaseItem $item, int $assetId)
    {
        $this->item = $item;
        $this->assetId = $assetId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ShowcaseItem
    {
        return $this->item;
    }

    public function setItem(ShowcaseItem $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getAssetId(): int
    {
        return $this->assetId;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): static
    {
        $caption = trim(strip_tags((string) $caption));
        $this->caption = $caption !== '' ? mb_substr($caption, 0, 191) : null;

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = $weight;

        return $this;
    }
}
