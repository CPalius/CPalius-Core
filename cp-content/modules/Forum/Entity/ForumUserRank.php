<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumUserRankRepository;

/**
 * Forum rank shown under the username in postbit.
 * Set $minPosts for auto-award by post count; null means admin-only (stored in User::$data['forum_rank_id']).
 */
#[ORM\Entity(repositoryClass: ForumUserRankRepository::class)]
#[ORM\Table(name: 'forum_user_ranks')]
class ForumUserRank
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $label;

    #[ORM\Column(type: 'string', length: 7)]
    private string $color = '#8B9DAF';

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'min_posts', type: 'integer', nullable: true)]
    private ?int $minPosts = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    public function __construct(string $label, string $color = '#8B9DAF', ?int $minPosts = null)
    {
        $this->label = $label;
        $this->color = $color;
        $this->minPosts = $minPosts;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getMinPosts(): ?int
    {
        return $this->minPosts;
    }

    public function setMinPosts(?int $minPosts): static
    {
        $this->minPosts = $minPosts;

        return $this;
    }

    public function isAutomatic(): bool
    {
        return $this->minPosts !== null;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
