<?php

declare(strict_types=1);

namespace Modules\Menu\Entity;

use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Localization\Contract\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Menu\Repository\MenuItemRepository;

/**
 * Menu item target is either an external URL or a nodeId — never both.
 * nodeId is a loose reference (not an FK); unresolved nodes are skipped at render time.
 */
#[ORM\Entity(repositoryClass: MenuItemRepository::class)]
#[ORM\Table(name: 'cp_menu_items')]
#[ORM\Index(columns: ['menu_id', 'parent_id'], name: 'idx_menu_item_parent')]
#[ORM\UniqueConstraint(name: 'uniq_menu_item_translation_group_locale', columns: ['translation_group_id', 'locale'])]
class MenuItem implements TranslatableInterface
{
    // Phase 3: translation_group_id + TranslatableTrait (TR/EN counterparts of one menu entry).
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Menu::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'menu_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Menu $menu;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    #[ORM\Column(type: 'string', length: 255)]
    private string $label;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'node_id', type: 'integer', nullable: true)]
    private ?int $nodeId = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'open_in_new_tab', type: 'boolean')]
    private bool $openInNewTab = false;

    /** @var list<string> rel values an operator may pick (noopener is added on its own for new tabs) */
    public const RELS = ['nofollow', 'sponsored', 'ugc'];

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $rel = null;

    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'css_class', type: 'string', length: 100, nullable: true)]
    private ?string $cssClass = null;

    public function __construct(Menu $menu, string $label, string $locale)
    {
        $this->menu = $menu;
        $this->label = $label;
        $this->locale = $locale;
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMenu(): Menu
    {
        return $this->menu;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getNodeId(): ?int
    {
        return $this->nodeId;
    }

    public function setNodeId(?int $nodeId): static
    {
        $this->nodeId = $nodeId;

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

    public function getRel(): ?string
    {
        return $this->rel;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getCssClass(): ?string
    {
        return $this->cssClass;
    }

    /**
     * Link extras, cleaned here so no caller can store anything the theme would have to distrust:
     * rel from the fixed list, an icon name (letters, digits, dash), class names (plus underscore, spaces).
     */
    public function setLinkAttributes(?string $rel, ?string $icon, ?string $cssClass): static
    {
        $this->rel = \in_array($rel, self::RELS, true) ? $rel : null;
        $icon = trim((string) $icon);
        $this->icon = preg_match('/^[A-Za-z0-9-]{1,60}$/', $icon) === 1 ? $icon : null;
        $cssClass = trim((string) preg_replace('/\s+/', ' ', (string) $cssClass));
        $this->cssClass = preg_match('/^[A-Za-z0-9_ -]{1,100}$/', $cssClass) === 1 ? $cssClass : null;

        return $this;
    }

    public function isOpenInNewTab(): bool
    {
        return $this->openInNewTab;
    }

    public function setOpenInNewTab(bool $openInNewTab): static
    {
        $this->openInNewTab = $openInNewTab;

        return $this;
    }
}
