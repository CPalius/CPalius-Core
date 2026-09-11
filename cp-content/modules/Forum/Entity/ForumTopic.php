<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumTopicRepository;

/**
 * CPalius Forum Engine thread (cp_forum_thread).
 *
 * node_id, prefix_id, title, slug, user_id, discussion_state
 * (visible|moderated|deleted), sticky, locked, view_count, reply_count,
 * first_post_id, last_post_id, last_post_date.
 */
#[ORM\Entity(repositoryClass: ForumTopicRepository::class)]
#[ORM\Table(name: 'forum_topics')]
#[ORM\Index(columns: ['updated_at'], name: 'idx_forum_topic_updated')]
#[ORM\Index(columns: ['state'], name: 'idx_forum_topic_state')]
#[ORM\Index(columns: ['sticky'], name: 'idx_forum_topic_sticky')]
#[ORM\Index(columns: ['slug'], name: 'idx_forum_topic_slug')]
#[ORM\Index(columns: ['discussion_state'], name: 'idx_forum_topic_discussion_state')]
#[ORM\Index(columns: ['last_post_date'], name: 'idx_forum_topic_last_post_date')]
#[ORM\Index(columns: ['locale'], name: 'idx_forum_topic_locale')]
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

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

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
    private bool $locked = false;

    #[ORM\Column(type: 'boolean')]
    private bool $sticky = false;

    #[ORM\Column(name: 'discussion_state', type: 'string', length: 16, enumType: ForumDiscussionState::class)]
    private ForumDiscussionState $discussionState = ForumDiscussionState::Visible;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'moved_to_topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $movedToTopic = null;

    #[ORM\Column(name: 'view_count', type: 'integer')]
    private int $viewCount = 0;

    #[ORM\Column(name: 'post_count', type: 'integer')]
    private int $postCount = 0;

    #[ORM\Column(name: 'first_post_id', type: 'integer', nullable: true)]
    private ?int $firstPostId = null;

    #[ORM\Column(name: 'last_post_id', type: 'integer', nullable: true)]
    private ?int $lastPostId = null;

    #[ORM\Column(name: 'last_post_date', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPostDate = null;

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
        $this->locale = $section->getLocale();
        $this->title = $title;
        $this->firstPosterName = $firstPosterName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->lastPostDate = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ForumSection
    {
        return $this->section;
    }

    /** CPalius Forum Engine cp_forum_thread.node_id */
    public function getNode(): ForumSection
    {
        return $this->section;
    }

    public function setSection(ForumSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

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
        $this->locked = $state === self::STATE_LOCKED;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked || $this->state === self::STATE_LOCKED;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;
        $this->state = $locked ? self::STATE_LOCKED : self::STATE_OPEN;

        return $this;
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

    public function getDiscussionState(): ForumDiscussionState
    {
        return $this->discussionState;
    }

    public function setDiscussionState(ForumDiscussionState $discussionState): static
    {
        $this->discussionState = $discussionState;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->discussionState === ForumDiscussionState::Visible;
    }

    public function isModerated(): bool
    {
        return $this->discussionState === ForumDiscussionState::Moderated;
    }

    public function isDeleted(): bool
    {
        return $this->discussionState === ForumDiscussionState::Deleted;
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

    /** Reply count excluding the opening post (cp_forum_thread.reply_count). */
    public function getReplyCount(): int
    {
        return max(0, $this->postCount - 1);
    }

    public function getFirstPostId(): ?int
    {
        return $this->firstPostId;
    }

    public function setFirstPostId(?int $firstPostId): static
    {
        $this->firstPostId = $firstPostId;

        return $this;
    }

    public function getLastPostId(): ?int
    {
        return $this->lastPostId;
    }

    public function setLastPostId(?int $lastPostId): static
    {
        $this->lastPostId = $lastPostId;

        return $this;
    }

    public function getLastPostDate(): ?\DateTimeImmutable
    {
        return $this->lastPostDate;
    }

    public function setLastPostDate(?\DateTimeImmutable $lastPostDate): static
    {
        $this->lastPostDate = $lastPostDate;

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
