<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Entity;

use App\Core\Annotation\CpResource;
use App\Core\Resource\Attribute\CpField;
use Modules\Whitepaper\Repository\WhitepaperDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The per-locale header of the public whitepaper: its title, its opening
 * passage and the version it claims to be.
 *
 * Kept apart from WhitepaperSection because these three fields belong to the
 * document, not to any one part of it, and because a version number that lives
 * inside a section would be edited by whoever happened to touch that section.
 *
 * The body used to be a 842-line PHP class of heredocs. It is an entity now so
 * the operator edits it from AACP instead of from an IDE; the content still
 * travels in git through WhitepaperConfigProvider, which is what makes a
 * database-backed document safe to deploy.
 */
#[ORM\Entity(repositoryClass: WhitepaperDocumentRepository::class)]
#[ORM\Table(name: 'cp_whitepaper_documents')]
#[ORM\UniqueConstraint(name: 'uniq_whitepaper_document_locale', columns: ['locale'])]
#[ORM\HasLifecycleCallbacks]
#[CpResource(
    name: 'whitepaper',
    module: 'whitepaper',
    capabilities: ['view', 'create', 'edit', 'delete'],
    auditable: true,
)]
class WhitepaperDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 10)]
    #[CpField(label: 'aacp.whitepaper.field.locale', sortable: true, searchable: true, priority: 1)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[CpField(label: 'aacp.whitepaper.field.title', searchable: true, priority: 2)]
    private string $title;

    /**
     * First-party HTML, authored by the maintainer and rendered as-is — the
     * same trust boundary the heredoc had. It is not user-submitted content
     * and deliberately does not go through the text-format pipeline, which
     * would strip the markup this document is made of.
     */
    #[ORM\Column(name: 'intro_html', type: Types::TEXT)]
    #[CpField(label: 'aacp.whitepaper.field.intro_html', list: false, widget: 'richtext', priority: 3)]
    private string $introHtml = '';

    #[ORM\Column(type: Types::STRING, length: 32)]
    #[CpField(label: 'aacp.whitepaper.field.version', priority: 4)]
    private string $version = 'v1.0.0';

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[CpField(label: 'aacp.whitepaper.field.updated_at', readonly: true, sortable: true, priority: 90)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $locale = 'en', string $title = '')
    {
        $this->locale = $locale;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getIntroHtml(): string
    {
        return $this->introHtml;
    }

    public function setIntroHtml(string $introHtml): static
    {
        $this->introHtml = $introHtml;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;

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
