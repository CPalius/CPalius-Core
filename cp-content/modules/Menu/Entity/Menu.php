<?php

namespace Modules\Menu\Entity;

use Modules\Menu\Repository\MenuRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Named menus (header/footer) for {{ cp_menu() }}. Admin CRUD lives in the optional Menu module.
 */
#[ORM\Entity(repositoryClass: MenuRepository::class)]
#[ORM\Table(name: 'menus')]
#[ORM\UniqueConstraint(name: 'uniq_menu_identifier', columns: ['identifier'])]
class Menu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Locale-less unique id for {{ cp_menu('header') }}; translations live on MenuItem::label.
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $identifier;

    /** @var Collection<int, MenuItem> */
    #[ORM\OneToMany(targetEntity: MenuItem::class, mappedBy: 'menu', orphanRemoval: true)]
    private Collection $items;

    public function __construct(string $name, string $identifier)
    {
        $this->name = $name;
        $this->identifier = $identifier;
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    /**
     * @return Collection<int, MenuItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }
}
