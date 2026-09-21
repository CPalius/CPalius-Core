<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumPollRepository;

#[ORM\Entity(repositoryClass: ForumPollRepository::class)]
#[ORM\Table(name: 'cp_forum_polls')]
#[ORM\UniqueConstraint(name: 'uniq_forum_poll_topic', columns: ['topic_id'])]
class ForumPoll
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumTopic $topic;

    #[ORM\Column(type: 'string', length: 255)]
    private string $question;

    #[ORM\Column(name: 'max_choices', type: 'smallint')]
    private int $maxChoices = 1;

    #[ORM\Column(name: 'hide_until_close', type: 'boolean')]
    private bool $hideUntilClose = false;

    #[ORM\Column(name: 'is_closed', type: 'boolean', options: ['default' => false])]
    private bool $closed = false;

    #[ORM\Column(name: 'is_public', type: 'boolean', options: ['default' => false])]
    private bool $publicVotes = false;

    #[ORM\Column(name: 'allow_change', type: 'boolean', options: ['default' => false])]
    private bool $allowChange = false;

    #[ORM\Column(name: 'closes_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closesAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ForumPollOption> */
    #[ORM\OneToMany(targetEntity: ForumPollOption::class, mappedBy: 'poll', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $options;

    public function __construct(ForumTopic $topic, string $question)
    {
        $this->topic = $topic;
        $this->question = $question;
        $this->createdAt = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getMaxChoices(): int
    {
        return $this->maxChoices;
    }

    public function setMaxChoices(int $maxChoices): static
    {
        $this->maxChoices = max(1, $maxChoices);

        return $this;
    }

    public function isHideUntilClose(): bool
    {
        return $this->hideUntilClose;
    }

    public function setHideUntilClose(bool $hide): static
    {
        $this->hideUntilClose = $hide;

        return $this;
    }

    public function getClosesAt(): ?\DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function setClosesAt(?\DateTimeImmutable $closesAt): static
    {
        $this->closesAt = $closesAt;

        return $this;
    }

    public function isClosed(): bool
    {
        if ($this->closed) {
            return true;
        }

        return $this->closesAt !== null && $this->closesAt <= new \DateTimeImmutable();
    }

    public function setClosed(bool $closed): static
    {
        $this->closed = $closed;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->publicVotes;
    }

    public function setPublic(bool $publicVotes): static
    {
        $this->publicVotes = $publicVotes;

        return $this;
    }

    public function allowsChange(): bool
    {
        return $this->allowChange;
    }

    public function setAllowChange(bool $allowChange): static
    {
        $this->allowChange = $allowChange;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ForumPollOption> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(ForumPollOption $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function getTotalVotes(): int
    {
        $sum = 0;
        foreach ($this->options as $option) {
            $sum += $option->getVoteCount();
        }

        return $sum;
    }
}
