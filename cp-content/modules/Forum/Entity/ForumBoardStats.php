<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumBoardStatsRepository;

/**
 * Locale-scoped board totals (cp_forum_board_stats).
 * Member count lives on CMF User — it is not incremented here.
 */
#[ORM\Entity(repositoryClass: ForumBoardStatsRepository::class)]
#[ORM\Table(name: 'cp_forum_board_stats')]
#[ORM\Index(columns: ['last_poster_id'], name: 'IDX_FORUM_BOARD_STATS_POSTER')]
class ForumBoardStats
{
    #[ORM\Id]
    #[ORM\Column(name: 'locale', type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(name: 'topic_count', type: 'integer', options: ['default' => 0])]
    private int $topicCount = 0;

    #[ORM\Column(name: 'post_count', type: 'integer', options: ['default' => 0])]
    private int $postCount = 0;

    #[ORM\Column(name: 'topic_count_held', type: 'integer', options: ['default' => 0])]
    private int $topicCountHeld = 0;

    #[ORM\Column(name: 'post_count_held', type: 'integer', options: ['default' => 0])]
    private int $postCountHeld = 0;

    #[ORM\Column(name: 'last_topic_id', type: 'integer', nullable: true)]
    private ?int $lastTopicId = null;

    #[ORM\Column(name: 'last_post_id', type: 'integer', nullable: true)]
    private ?int $lastPostId = null;

    #[ORM\Column(name: 'last_post_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPostAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_poster_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lastPoster = null;

    #[ORM\Column(name: 'last_poster_name', type: 'string', length: 100, nullable: true)]
    private ?string $lastPosterName = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $locale)
    {
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTopicCount(): int
    {
        return $this->topicCount;
    }

    public function setTopicCount(int $topicCount): static
    {
        $this->topicCount = max(0, $topicCount);
        $this->touch();

        return $this;
    }

    public function getPostCount(): int
    {
        return $this->postCount;
    }

    public function setPostCount(int $postCount): static
    {
        $this->postCount = max(0, $postCount);
        $this->touch();

        return $this;
    }

    public function getTopicCountHeld(): int
    {
        return $this->topicCountHeld;
    }

    public function setTopicCountHeld(int $topicCountHeld): static
    {
        $this->topicCountHeld = max(0, $topicCountHeld);
        $this->touch();

        return $this;
    }

    public function getPostCountHeld(): int
    {
        return $this->postCountHeld;
    }

    public function setPostCountHeld(int $postCountHeld): static
    {
        $this->postCountHeld = max(0, $postCountHeld);
        $this->touch();

        return $this;
    }

    public function getLastTopicId(): ?int
    {
        return $this->lastTopicId;
    }

    public function setLastTopicId(?int $lastTopicId): static
    {
        $this->lastTopicId = $lastTopicId;
        $this->touch();

        return $this;
    }

    public function getLastPostId(): ?int
    {
        return $this->lastPostId;
    }

    public function setLastPostId(?int $lastPostId): static
    {
        $this->lastPostId = $lastPostId;
        $this->touch();

        return $this;
    }

    public function getLastPostAt(): ?\DateTimeImmutable
    {
        return $this->lastPostAt;
    }

    public function setLastPostAt(?\DateTimeImmutable $lastPostAt): static
    {
        $this->lastPostAt = $lastPostAt;
        $this->touch();

        return $this;
    }

    public function getLastPoster(): ?User
    {
        return $this->lastPoster;
    }

    public function setLastPoster(?User $lastPoster): static
    {
        $this->lastPoster = $lastPoster;
        $this->touch();

        return $this;
    }

    public function getLastPosterName(): ?string
    {
        return $this->lastPosterName;
    }

    public function setLastPosterName(?string $lastPosterName): static
    {
        $this->lastPosterName = $lastPosterName;
        $this->touch();

        return $this;
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
