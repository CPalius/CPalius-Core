<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumPostVoteRepository;

/**
 * One member's verdict on one post: like or dislike, never both.
 *
 * Replaces ForumPostLike and ForumPostDislike, which were the same four columns
 * and the same six repository methods written twice — the only thing separating
 * them was which table you inserted into.
 *
 * The merge buys more than one table. Exclusivity used to live in
 * ForumTopicService::toggleLike(), which deletes the opposing row before
 * inserting its own; nothing in the database enforced it, so two requests
 * arriving together could leave a member holding a like and a dislike on the
 * same post. UNIQUE(post_id, user_id) makes that state unrepresentable.
 *
 * Storing the verdict as +1 / -1 rather than a flag also makes a post's score a
 * single SUM instead of two COUNTs and a subtraction.
 */
#[ORM\Entity(repositoryClass: ForumPostVoteRepository::class)]
#[ORM\Table(name: 'cp_forum_post_votes')]
#[ORM\UniqueConstraint(name: 'uniq_cp_forum_post_vote', columns: ['post_id', 'user_id'])]
#[ORM\Index(name: 'idx_cp_forum_post_vote_user', columns: ['user_id'])]
class ForumPostVote
{
    public const LIKE = 1;
    public const DISLIKE = -1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPost $post;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * self::LIKE or self::DISLIKE.
     */
    #[ORM\Column(name: 'vote', type: 'smallint')]
    private int $vote;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ForumPost $post, User $user, int $vote)
    {
        $this->post = $post;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->setVote($vote);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVote(): int
    {
        return $this->vote;
    }

    /**
     * Flipping an existing row rather than deleting and re-inserting keeps the
     * unique constraint happy without a transaction around two statements.
     */
    public function setVote(int $vote): void
    {
        if ($vote !== self::LIKE && $vote !== self::DISLIKE) {
            throw new \InvalidArgumentException(\sprintf('A post vote is %d or %d, got %d.', self::LIKE, self::DISLIKE, $vote));
        }

        $this->vote = $vote;
    }

    public function isLike(): bool
    {
        return $this->vote === self::LIKE;
    }

    public function isDislike(): bool
    {
        return $this->vote === self::DISLIKE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
