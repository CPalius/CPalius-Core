<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumLinkPreviewRepository;

#[ORM\Entity(repositoryClass: ForumLinkPreviewRepository::class)]
#[ORM\Table(name: 'forum_link_previews')]
#[ORM\UniqueConstraint(name: 'uniq_forum_link_preview_hash', columns: ['url_hash'])]
class ForumLinkPreview
{
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'url_hash', type: 'string', length: 64)]
    private string $urlHash;

    #[ORM\Column(type: 'string', length: 2048)]
    private string $url;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'image_url', type: 'string', length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'site_name', type: 'string', length: 255, nullable: true)]
    private ?string $siteName = null;

    #[ORM\Column(name: 'favicon_url', type: 'string', length: 512, nullable: true)]
    private ?string $faviconUrl = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_FAILED;

    #[ORM\Column(name: 'fetched_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(string $urlHash, string $url)
    {
        $this->urlHash = $urlHash;
        $this->url = $url;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getSiteName(): ?string
    {
        return $this->siteName;
    }

    public function getFaviconUrl(): ?string
    {
        return $this->faviconUrl;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function markReady(
        ?string $title,
        ?string $description,
        ?string $imageUrl,
        ?string $siteName,
        ?string $faviconUrl,
    ): void {
        $this->title = $this->clip($title, 255);
        $this->description = $this->clip($description, 512);
        $this->imageUrl = $this->clip($imageUrl, 2048);
        $this->siteName = $this->clip($siteName, 255);
        $this->faviconUrl = $this->clip($faviconUrl, 512);
        $this->status = self::STATUS_READY;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function markFailed(): void
    {
        $this->status = self::STATUS_FAILED;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
