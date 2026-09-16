<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumPollVoteRepository;

#[ORM\Entity(repositoryClass: ForumPollVoteRepository::class)]
#[ORM\Table(name: 'cp_forum_poll_votes')]
#[ORM\UniqueConstraint(name: 'uniq_forum_poll_vote', columns: ['poll_id', 'user_id', 'option_id'])]
class ForumPollVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPoll::class)]
    #[ORM\JoinColumn(name: 'poll_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPoll $poll;

    #[ORM\ManyToOne(targetEntity: ForumPollOption::class)]
    #[ORM\JoinColumn(name: 'option_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPollOption $option;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ForumPoll $poll, ForumPollOption $option, User $user)
    {
        $this->poll = $poll;
        $this->option = $option;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoll(): ForumPoll
    {
        return $this->poll;
    }

    public function getOption(): ForumPollOption
    {
        return $this->option;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
