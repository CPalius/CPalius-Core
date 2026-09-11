<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumPollOptionRepository;

#[ORM\Entity(repositoryClass: ForumPollOptionRepository::class)]
#[ORM\Table(name: 'forum_poll_options')]
#[ORM\Index(columns: ['poll_id'], name: 'idx_forum_poll_opt_poll')]
class ForumPollOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPoll::class, inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'poll_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPoll $poll;

    #[ORM\Column(type: 'string', length: 255)]
    private string $label;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'vote_count', type: 'integer')]
    private int $voteCount = 0;

    public function __construct(ForumPoll $poll, string $label, int $sortOrder = 0)
    {
        $this->poll = $poll;
        $this->label = $label;
        $this->sortOrder = $sortOrder;
        $poll->addOption($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoll(): ForumPoll
    {
        return $this->poll;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getVoteCount(): int
    {
        return $this->voteCount;
    }

    public function incrementVoteCount(): void
    {
        ++$this->voteCount;
    }
}
