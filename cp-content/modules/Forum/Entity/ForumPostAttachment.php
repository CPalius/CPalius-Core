<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\Asset;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumPostAttachmentRepository;

#[ORM\Entity(repositoryClass: ForumPostAttachmentRepository::class)]
#[ORM\Table(name: 'cp_forum_post_attachments')]
#[ORM\Index(columns: ['post_id'], name: 'idx_forum_attach_post')]
class ForumPostAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPost $post;

    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(name: 'original_name', type: 'string', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 100)]
    private string $mimeType;

    #[ORM\Column(name: 'file_size', type: 'integer')]
    private int $fileSize;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ForumPost $post, Asset $asset)
    {
        $this->post = $post;
        $this->asset = $asset;
        $this->originalName = $asset->getOriginalName();
        $this->mimeType = $asset->getMimeType();
        $this->fileSize = $asset->getFileSize();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUrl(): string
    {
        return '/uploads/'.$this->asset->getStorageKey();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
