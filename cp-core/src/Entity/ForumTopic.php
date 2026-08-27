<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumTopicRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Forum konusu — Cotonti cot_forum_topics tablosunun Doctrine karşılığı.
 */
#[ORM\Entity(repositoryClass: ForumTopicRepository::class)]
#[ORM\Table(name: 'forum_topics')]
#[ORM\Index(columns: ['updated_at'], name: 'idx_forum_topic_updated')]
#[ORM\Index(columns: ['state'], name: 'idx_forum_topic_state')]
#[ORM\Index(columns: ['sticky'], name: 'idx_forum_topic_sticky')]
#[ORM\Index(columns: ['slug'], name: 'idx_forum_topic_slug')]
class ForumTopic
{
    public const MODE_NORMAL = 0;
    public const MODE_PRIVATE = 1;

    public const STATE_OPEN = 0;
    public const STATE_LOCKED = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumSection $section;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $slug = null;

    #[ORM\ManyToOne(targetEntity: ForumTopicPrefix::class)]
    #[ORM\JoinColumn(name: 'prefix_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ForumTopicPrefix $prefix = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'smallint')]
    private int $mode = self::MODE_NORMAL;

    #[ORM\Column(type: 'smallint')]
    private int $state = self::STATE_OPEN;

    #[ORM\Column(type: 'boolean')]
    private bool $sticky = false;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'moved_to_topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $movedToTopic = null;

    #[ORM\Column(name: 'view_count', type: 'integer')]
    private int $viewCount = 0;

    #[ORM\Column(name: 'post_count', type: 'integer')]
    private int $postCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'first_poster_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $firstPoster = null;

    #[ORM\Column(name: 'first_poster_name', type: 'string', length: 100)]
    private string $firstPosterName;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_poster_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lastPoster = null;

    #[ORM\Column(name: 'last_poster_name', type: 'string', length: 100, nullable: true)]
    private ?string $lastPosterName = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $preview = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ForumSection $section, string $title, string $firstPosterName)
    {
        $this->section = $section;
        $this->title = $title;
        $this->firstPosterName = $firstPosterName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ForumSection
    {
        return $this->section;
    }

    public function setSection(ForumSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPrefix(): ?ForumTopicPrefix
    {
        return $this->prefix;
    }

    public function setPrefix(?ForumTopicPrefix $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getMode(): int
    {
        return $this->mode;
    }

    public function setMode(int $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->mode === self::MODE_PRIVATE;
    }

    public function getState(): int
    {
        return $this->state;
    }

    public function setState(int $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->state === self::STATE_LOCKED;
    }

    public function isSticky(): bool
    {
        return $this->sticky;
    }

    public function setSticky(bool $sticky): static
    {
        $this->sticky = $sticky;

        return $this;
    }

    public function getMovedToTopic(): ?self
    {
        return $this->movedToTopic;
    }

    public function setMovedToTopic(?self $movedToTopic): static
    {
        $this->movedToTopic = $movedToTopic;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function setViewCount(int $viewCount): static
    {
        $this->viewCount = $viewCount;

        return $this;
    }

    public function incrementViewCount(): static
    {
        ++$this->viewCount;

        return $this;
    }

    public function getPostCount(): int
    {
        return $this->postCount;
    }

    public function setPostCount(int $postCount): static
    {
        $this->postCount = $postCount;

        return $this;
    }

    public function getFirstPoster(): ?User
    {
        return $this->firstPoster;
    }

    public function setFirstPoster(?User $firstPoster): static
    {
        $this->firstPoster = $firstPoster;

        return $this;
    }

    public function getFirstPosterName(): string
    {
        return $this->firstPosterName;
    }

    public function setFirstPosterName(string $firstPosterName): static
    {
        $this->firstPosterName = $firstPosterName;

        return $this;
    }

    public function getLastPoster(): ?User
    {
        return $this->lastPoster;
    }

    public function setLastPoster(?User $lastPoster): static
    {
        $this->lastPoster = $lastPoster;

        return $this;
    }

    public function getLastPosterName(): ?string
    {
        return $this->lastPosterName;
    }

    public function setLastPosterName(?string $lastPosterName): static
    {
        $this->lastPosterName = $lastPosterName;

        return $this;
    }

    public function getPreview(): ?string
    {
        return $this->preview;
    }

    public function setPreview(?string $preview): static
    {
        $this->preview = $preview;

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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
