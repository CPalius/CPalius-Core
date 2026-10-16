<?php

declare(strict_types=1);

namespace Modules\Media\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A folder in the media library. Pure organisation: files do not move on disk,
 * only their cp_media_placements row changes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_media_folders')]
#[ORM\Index(columns: ['parent_id'], name: 'idx_media_folder_parent')]
class MediaFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Null = top level. */
    #[ORM\Column(name: 'parent_id', type: 'integer', nullable: true)]
    private ?int $parentId;

    #[ORM\Column(type: 'string', length: 150)]
    private string $name;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'user_id', type: 'integer', nullable: true)]
    private ?int $userId;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTime $createdAt;

    public function __construct(string $name, ?int $parentId, ?int $userId)
    {
        $this->name = $name;
        $this->parentId = $parentId;
        $this->userId = $userId;
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function setParentId(?int $parentId): static
    {
        $this->parentId = $parentId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
