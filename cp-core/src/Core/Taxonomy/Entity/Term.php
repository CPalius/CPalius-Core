<?php

declare(strict_types=1);

namespace App\Core\Taxonomy\Entity;

use App\Core\Entity\Attribute\CpEntityType;
use App\Core\Entity\FieldableInterface;
use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Localization\Contract\TranslatableTrait;
use App\Core\Taxonomy\Repository\TermRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One term in a vocabulary. Fieldable (bundle = the vocabulary machine_name) and
 * translatable (siblings share translation_group_id). Hierarchy is a self
 * parent/children relation, honoured only when the vocabulary is hierarchical.
 */
#[ORM\Entity(repositoryClass: TermRepository::class)]
#[ORM\Table(name: 'cp_terms')]
#[ORM\Index(columns: ['vocabulary_id', 'weight'], name: 'idx_term_vocab_weight')]
#[ORM\Index(columns: ['vocabulary_id', 'parent_id'], name: 'idx_term_vocab_parent')]
#[ORM\Index(columns: ['locale'], name: 'idx_term_locale')]
#[ORM\UniqueConstraint(name: 'uniq_term_vocab_slug_locale', columns: ['vocabulary_id', 'slug', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_term_translation_group_locale', columns: ['translation_group_id', 'locale'])]
#[CpEntityType(id: 'taxonomy_term', label: 'entity.type.taxonomy_term', bundleable: true, translatable: true)]
class Term implements TranslatableInterface, FieldableInterface
{
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vocabulary::class)]
    #[ORM\JoinColumn(name: 'vocabulary_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vocabulary $vocabulary;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Unique per (vocabulary, locale) — never globally.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    /**
     * Custom field values (Field API bundle = vocabulary machine_name).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Vocabulary $vocabulary, string $name, string $slug, string $locale)
    {
        $this->vocabulary = $vocabulary;
        $this->name = $name;
        $this->slug = $slug;
        $this->locale = $locale;
        $this->children = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVocabulary(): Vocabulary
    {
        return $this->vocabulary;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this->touch();
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this->touch();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this->touch();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

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

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getDataValue(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getDescription(): ?string
    {
        $value = $this->getDataValue('description');

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function setDescription(?string $description): static
    {
        $data = $this->data;
        if ($description === null || trim($description) === '') {
            unset($data['description']);
        } else {
            $data['description'] = trim($description);
        }
        $this->data = $data;

        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * FieldableInterface — bundle is the vocabulary machine_name.
     */
    public function fieldableEntityTypeId(): string
    {
        return 'taxonomy_term';
    }

    public function fieldableBundle(): string
    {
        return $this->vocabulary->getMachineName();
    }

    public function fieldableLocale(): string
    {
        return $this->locale;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldableData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setFieldableData(array $data): void
    {
        $this->data = $data;
        $this->touch();
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
