<?php

declare(strict_types=1);

namespace Modules\Roadmap\Entity;

use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Native roadmap row: milestone card or changelog update (not a Blog Node / Forum Topic hybrid).
 */
#[ORM\Entity(repositoryClass: RoadmapEntryRepository::class)]
#[ORM\Table(name: 'roadmap_entries')]
#[ORM\Index(columns: ['locale', 'status'], name: 'idx_roadmap_locale_status')]
#[ORM\Index(columns: ['locale', 'kind'], name: 'idx_roadmap_locale_kind')]
#[ORM\Index(columns: ['published_at'], name: 'idx_roadmap_published')]
#[ORM\UniqueConstraint(name: 'uniq_roadmap_slug_locale', columns: ['slug', 'locale'])]
class RoadmapEntry
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    public const KIND_MILESTONE = 'milestone';
    public const KIND_UPDATE = 'update';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SHIPPED,
        self::STATUS_CANCELLED,
    ];

    public const KINDS = [
        self::KIND_MILESTONE,
        self::KIND_UPDATE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 190)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(type: 'text')]
    private string $summary = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status = self::STATUS_PLANNED;

    #[ORM\Column(type: 'string', length: 32)]
    private string $kind = self::KIND_MILESTONE;

    #[ORM\Column(name: 'version_label', type: 'string', length: 64, nullable: true)]
    private ?string $versionLabel = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_featured', type: 'boolean')]
    private bool $isFeatured = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, string $slug, string $locale)
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->locale = $locale;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        $this->touch();

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;
        $this->touch();

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid roadmap status "%s".', $status));
        }

        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid roadmap kind "%s".', $kind));
        }

        $this->kind = $kind;
        $this->touch();

        return $this;
    }

    public function getVersionLabel(): ?string
    {
        return $this->versionLabel;
    }

    public function setVersionLabel(?string $versionLabel): static
    {
        $this->versionLabel = $versionLabel;
        $this->touch();

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        $this->touch();

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        $this->touch();

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        $this->touch();

        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isPubliclyVisible(): bool
    {
        if ($this->status === self::STATUS_CANCELLED || $this->publishedAt === null) {
            return false;
        }

        // Naive DATETIME; list queries already filter with CURRENT_TIMESTAMP().
        // Show page: publishedAt set and not cancelled counts as public.
        return true;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
