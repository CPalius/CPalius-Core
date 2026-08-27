<?php

namespace App\Entity;

use App\Repository\MenuRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Platform seviyesi bir yetenek (Category/Asset gibi core'a ait): herhangi
 * bir tema kendi "header"/"footer" gibi bir tanımlayıcıyla bu menüleri
 * cp_menu() Twig fonksiyonu üzerinden çağırabilir (bkz. FrontMenuRuntime).
 * Admin CRUD arayüzü ayrı, opsiyonel bir modülde (cp-content/modules/Menu/)
 * sunulur — bir tema menü kullanmak zorunda değildir.
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
     * cp_menu('header') çağrısıyla eşleşen global-unique kimlik. Bilinçli
     * olarak locale'siz: bir menü yapısı genelde tüm dillerde aynı
     * identifier'ı paylaşır, çeviri MenuItem::label seviyesinde çözülür.
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
