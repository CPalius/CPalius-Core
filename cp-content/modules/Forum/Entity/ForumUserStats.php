<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumUserStatsRepository;

/**
 * Per-member denormalized forum counters (cp_forum_user_stats).
 * One row per user; missing row means every count is zero.
 */
#[ORM\Entity(repositoryClass: ForumUserStatsRepository::class)]
#[ORM\Table(name: 'cp_forum_user_stats')]
#[ORM\Index(columns: ['post_count'], name: 'idx_forum_user_stats_posts')]
class ForumUserStats
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Visible posts (opening post included). Held posts do not increment this. */
    #[ORM\Column(name: 'post_count', type: 'integer', options: ['default' => 0])]
    private int $postCount = 0;

    /** Visible, unmoved topics the member opened. */
    #[ORM\Column(name: 'topic_count', type: 'integer', options: ['default' => 0])]
    private int $topicCount = 0;

    #[ORM\Column(name: 'like_received', type: 'integer', options: ['default' => 0])]
    private int $likeReceived = 0;

    #[ORM\Column(name: 'warning_points', type: 'integer', options: ['default' => 0])]
    private int $warningPoints = 0;

    #[ORM\Column(name: 'last_posted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPostedAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
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

    public function getLikeReceived(): int
    {
        return $this->likeReceived;
    }

    public function setLikeReceived(int $likeReceived): static
    {
        $this->likeReceived = max(0, $likeReceived);
        $this->touch();

        return $this;
    }

    public function getWarningPoints(): int
    {
        return $this->warningPoints;
    }

    public function setWarningPoints(int $warningPoints): static
    {
        $this->warningPoints = max(0, $warningPoints);
        $this->touch();

        return $this;
    }

    public function getLastPostedAt(): ?\DateTimeImmutable
    {
        return $this->lastPostedAt;
    }

    public function setLastPostedAt(?\DateTimeImmutable $lastPostedAt): static
    {
        $this->lastPostedAt = $lastPostedAt;
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
