<?php

declare(strict_types=1);

namespace Modules\Menu\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Menu\Repository\MenuRepository;

/**
 * Named menus (header/footer) for {{ cp_menu() }}. Admin CRUD lives in the optional Menu module.
 */
#[ORM\Entity(repositoryClass: MenuRepository::class)]
#[ORM\Table(name: 'cp_menu_menus')]
#[ORM\UniqueConstraint(name: 'uniq_menu_identifier', columns: ['identifier'])]
#[ORM\UniqueConstraint(name: 'uniq_menu_location', columns: ['location'])]
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

    /** Theme or module location this menu fills, or null; one menu per location. */
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $location = null;

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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $location = $location === null ? '' : trim($location);
        $this->location = preg_match('/^[a-z0-9_]{1,60}$/', $location) === 1 ? $location : null;

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
