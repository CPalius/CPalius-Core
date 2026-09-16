<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A pointer from a showcase item to somewhere else: the forum thread where the
 * product is supported, a blog post announcing a release, a repository, a demo.
 *
 * Internal destinations are stored as a ROUTE NAME plus its parameters, not as a
 * URL and not as a foreign key into another module's tables. That single choice
 * is what makes the integration safe:
 *
 *   - Showcase never imports Modules\Forum or Modules\Blog, so it still compiles
 *     and boots when neither is installed (Manifesto Law 2).
 *   - Deactivating Forum removes its routes; ShowcaseLinkService then fails to
 *     generate the URL and hides the link instead of rendering a dead one.
 *   - A module changing its URL scheme moves its own links along with it.
 *
 * External destinations keep an absolute URL, validated to http/https on write.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_showcase_links')]
#[ORM\Index(columns: ['item_id', 'weight'], name: 'idx_showcase_link_item_weight')]
class ShowcaseLink
{
    /** Internal route inside this installation (forum topic, blog post, page…). */
    public const KIND_INTERNAL = 'internal';
    /** Absolute http(s) address outside the site. */
    public const KIND_EXTERNAL = 'external';

    public const KINDS = [self::KIND_INTERNAL, self::KIND_EXTERNAL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShowcaseItem::class, inversedBy: 'links')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShowcaseItem $item;

    #[ORM\Column(type: 'string', length: 24)]
    private string $kind;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'route_name', type: 'string', length: 191, nullable: true)]
    private ?string $routeName = null;

    /** @var array<string, string|int> */
    #[ORM\Column(name: 'route_params', type: 'json')]
    private array $routeParams = [];

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ShowcaseItem $item, string $kind)
    {
        $this->item = $item;
        $this->kind = \in_array($kind, self::KINDS, true) ? $kind : self::KIND_EXTERNAL;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ShowcaseItem
    {
        return $this->item;
    }

    public function setItem(ShowcaseItem $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isInternal(): bool
    {
        return $this->kind === self::KIND_INTERNAL;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $label = trim(strip_tags((string) $label));
        $this->label = $label !== '' ? mb_substr($label, 0, 191) : null;

        return $this;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /**
     * @param array<string, string|int> $params
     */
    public function setRoute(string $routeName, array $params): static
    {
        $this->routeName = mb_substr($routeName, 0, 191);
        $this->routeParams = $params;
        $this->url = null;

        return $this;
    }

    /**
     * @return array<string, string|int>
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * Absolute external address. Callers must have validated the scheme first
     * (ShowcaseUrlValidator); this setter only enforces the column length.
     */
    public function setUrl(?string $url): static
    {
        $this->url = $url !== null && $url !== '' ? mb_substr($url, 0, 500) : null;
        $this->routeName = null;
        $this->routeParams = [];

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
