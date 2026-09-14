<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Entity;

use App\Core\Annotation\CpResource;
use App\Core\Resource\Attribute\CpField;
use Modules\Whitepaper\Repository\WhitepaperSectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One chapter of the public whitepaper, per locale.
 *
 * The slug is the page anchor, so it is part of the document's public contract:
 * links people have already shared point at it. It is therefore edited
 * deliberately rather than derived from the title, which changes freely.
 *
 * Ordering is an explicit weight rather than insertion order: a chapter
 * inserted later often belongs in the middle, and renumbering a list every
 * time is exactly the chore an operator would stop doing.
 */
#[ORM\Entity(repositoryClass: WhitepaperSectionRepository::class)]
#[ORM\Table(name: 'cp_whitepaper_sections')]
#[ORM\UniqueConstraint(name: 'uniq_whitepaper_section', columns: ['locale', 'slug'])]
#[ORM\Index(name: 'idx_whitepaper_section_order', columns: ['locale', 'weight'])]
#[ORM\HasLifecycleCallbacks]
#[CpResource(
    name: 'whitepaper_section',
    module: 'whitepaper',
    capabilities: ['view', 'create', 'edit', 'delete'],
    auditable: true,
)]
class WhitepaperSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 10)]
    #[CpField(label: 'aacp.whitepaper.field.locale', sortable: true, searchable: true, priority: 1)]
    private string $locale;

    /** The anchor in the rendered page (`#pristine-root`), not a derived slug. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    #[CpField(label: 'aacp.whitepaper.field.slug', sortable: true, searchable: true, priority: 2)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[CpField(label: 'aacp.whitepaper.field.title', searchable: true, priority: 3)]
    private string $title;

    #[ORM\Column(type: Types::INTEGER)]
    #[CpField(label: 'aacp.whitepaper.field.weight', sortable: true, priority: 4)]
    private int $weight = 0;

    /** First-party HTML, rendered as-is — see WhitepaperDocument::$introHtml. */
    #[ORM\Column(name: 'body_html', type: Types::TEXT)]
    #[CpField(label: 'aacp.whitepaper.field.body_html', list: false, widget: 'richtext', priority: 5)]
    private string $bodyHtml = '';

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[CpField(label: 'aacp.whitepaper.field.updated_at', readonly: true, sortable: true, priority: 90)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $locale = 'en', string $slug = '', string $title = '')
    {
        $this->locale = $locale;
        $this->slug = $slug;
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(string $bodyHtml): static
    {
        $this->bodyHtml = $bodyHtml;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
