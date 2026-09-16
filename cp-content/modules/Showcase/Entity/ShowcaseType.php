<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Showcase\Repository\ShowcaseTypeRepository;

/**
 * One kind of thing the showcase can hold: "website", "vehicle", "saas", "service".
 *
 * A type is a BUNDLE. Its machine name becomes the Field API bundle key
 * (see fieldBundle()), so every type carries its own custom-field schema and two
 * sites running this module can have nothing in common but the table layout.
 *
 * The built-in columns on ShowcaseItem (price, urls, contact, location) are the
 * handful of attributes almost every listing needs; $settings decides which of
 * them a given type actually shows. Everything else is a FieldDefinition.
 */
#[ORM\Entity(repositoryClass: ShowcaseTypeRepository::class)]
#[ORM\Table(name: 'cp_showcase_types')]
#[ORM\UniqueConstraint(name: 'uniq_showcase_type_machine', columns: ['machine_name'])]
#[ORM\Index(columns: ['enabled', 'weight'], name: 'idx_showcase_type_enabled_weight')]
class ShowcaseType
{
    /**
     * Capped at 32 so "showcase_<machine_name>" stays inside the 50-character
     * bundle pattern the core field screens route on.
     */
    public const MACHINE_NAME_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    public const BUNDLE_PREFIX = 'showcase_';

    /** Optional built-in columns a type may switch on, with their default state. */
    public const FEATURES = [
        'price' => true,
        'gallery' => true,
        'external_url' => true,
        'demo_url' => false,
        'contact' => true,
        'location' => false,
        'body' => true,
        'reviews' => true,
        'links' => true,
        'categories' => true,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'machine_name', type: 'string', length: 32)]
    private string $machineName;

    #[ORM\Column(type: 'string', length: 64)]
    private string $icon = 'heroicons:cube';

    /**
     * Machine name of the taxonomy vocabulary holding this type's categories, or
     * null when the type has no category tree. Created on demand by
     * ShowcaseTypeManager so car brands and software categories never mix.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $vocabulary = null;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $settings = [];

    /** @var Collection<int, ShowcaseTypeTranslation> */
    #[ORM\OneToMany(mappedBy: 'type', targetEntity: ShowcaseTypeTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $machineName)
    {
        $this->machineName = $machineName;
        $this->translations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachineName(): string
    {
        return $this->machineName;
    }

    /**
     * Field API bundle key for this type's custom fields. Stable for the lifetime
     * of the type — the machine name is immutable once rows exist, because the
     * FieldDefinition rows are keyed on it.
     */
    public function fieldBundle(): string
    {
        return self::BUNDLE_PREFIX.$this->machineName;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): static
    {
        $icon = trim($icon);
        $this->icon = $icon !== '' ? mb_substr($icon, 0, 64) : 'heroicons:cube';

        return $this->touch();
    }

    public function getVocabulary(): ?string
    {
        return $this->vocabulary;
    }

    public function setVocabulary(?string $vocabulary): static
    {
        $this->vocabulary = $vocabulary !== null && $vocabulary !== '' ? mb_substr($vocabulary, 0, 64) : null;

        return $this->touch();
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = $weight;

        return $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;

        return $this->touch();
    }

    /**
     * Unknown keys fall back to the FEATURES default, so a type row written by an
     * older module version keeps working after a feature is added.
     */
    public function supports(string $feature): bool
    {
        if (!\array_key_exists($feature, self::FEATURES)) {
            return false;
        }

        return (bool) ($this->settings['features'][$feature] ?? self::FEATURES[$feature]);
    }

    /**
     * @return array<string, bool>
     */
    public function features(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $out[$feature] = $this->supports($feature);
        }

        return $out;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;

        return $this->touch();
    }

    /**
     * @return Collection<int, ShowcaseTypeTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?ShowcaseTypeTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function addTranslation(ShowcaseTypeTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setType($this);
        }

        return $this->touch();
    }

    public function removeTranslation(ShowcaseTypeTranslation $translation): static
    {
        $this->translations->removeElement($translation);

        return $this->touch();
    }

    /**
     * Label in the requested locale, then any other locale, then the machine name.
     * Never returns an empty string: a type with no translation row still has to
     * render in menus and breadcrumbs.
     */
    public function label(string $locale): string
    {
        $exact = $this->getTranslation($locale);
        if ($exact !== null && $exact->getLabel() !== '') {
            return $exact->getLabel();
        }

        foreach ($this->translations as $translation) {
            if ($translation->getLabel() !== '') {
                return $translation->getLabel();
            }
        }

        return $this->machineName;
    }

    public function description(string $locale): ?string
    {
        return $this->getTranslation($locale)?->getDescription();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
