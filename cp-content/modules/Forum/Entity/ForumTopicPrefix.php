<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumTopicPrefixRepository;

/**
 * Topic prefix badge (cp_forum_prefix): title, css_class, display_order.
 * An empty sections collection means the prefix is available in all forums.
 */
#[ORM\Entity(repositoryClass: ForumTopicPrefixRepository::class)]
#[ORM\Table(name: 'forum_topic_prefixes')]
class ForumTopicPrefix
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $label;

    #[ORM\Column(type: 'string', length: 7)]
    private string $color = '#4AADE4';

    #[ORM\Column(name: 'css_class', type: 'string', length: 64, nullable: true)]
    private ?string $cssClass = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    /** @var Collection<int, ForumSection> */
    #[ORM\ManyToMany(targetEntity: ForumSection::class)]
    #[ORM\JoinTable(name: 'forum_prefix_sections')]
    #[ORM\JoinColumn(name: 'prefix_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'section_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $sections;

    public function __construct(string $label, string $color = '#4AADE4')
    {
        $this->label = $label;
        $this->color = $color;
        $this->sections = new ArrayCollection();
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

    /** CPalius Forum Engine prefix title */
    public function getTitle(): string
    {
        return $this->label;
    }

    public function setTitle(string $title): static
    {
        $this->label = $title;

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

    public function getCssClass(): ?string
    {
        return $this->cssClass;
    }

    public function setCssClass(?string $cssClass): static
    {
        $this->cssClass = $cssClass !== null && $cssClass !== '' ? $cssClass : null;

        return $this;
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

    /** CPalius Forum Engine prefix display order */
    public function getDisplayOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return Collection<int, ForumSection> */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function clearSections(): static
    {
        $this->sections->clear();

        return $this;
    }

    public function addSection(ForumSection $section): static
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
        }

        return $this;
    }

    public function isAvailableIn(?ForumSection $section): bool
    {
        if ($this->sections->isEmpty()) {
            return true;
        }

        if ($section === null) {
            return false;
        }

        foreach ($this->sections as $allowed) {
            if ($allowed->getId() === $section->getId()) {
                return true;
            }
        }

        return false;
    }

    public function isSolved(): bool
    {
        $class = $this->cssClass ?? '';

        return str_contains($class, 'solved')
            || mb_strtolower($this->label) === 'çözüldü'
            || mb_strtolower($this->label) === 'solved';
    }
}
