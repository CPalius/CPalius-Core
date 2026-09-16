<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Per-locale label and description for a ShowcaseType.
 * Composite unique (type, locale) — Law 5.2, no global unique on locale.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_showcase_type_translations')]
#[ORM\UniqueConstraint(name: 'uniq_showcase_type_locale', columns: ['type_id', 'locale'])]
class ShowcaseTypeTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShowcaseType::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShowcaseType $type;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(type: 'string', length: 191)]
    private string $label;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    public function __construct(ShowcaseType $type, string $locale, string $label)
    {
        $this->type = $type;
        $this->locale = $locale;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ShowcaseType
    {
        return $this->type;
    }

    public function setType(ShowcaseType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr(trim(strip_tags($label)), 0, 191);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = $description !== null ? trim(strip_tags($description)) : '';
        $this->description = $description !== '' ? mb_substr($description, 0, 500) : null;

        return $this;
    }
}
