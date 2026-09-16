<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Annotation\Publishable;
use App\Core\Annotation\SoftDeletable;
use App\Core\Database\Traits\SoftDeletableTrait;
use App\Core\Entity\Attribute\CpEntityType;
use App\Core\Entity\FieldableInterface;
use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Security\OwnableInterface;
use App\Core\Taxonomy\Entity\Term;
use App\Repository\NodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Hybrid single-table content model: hot fields as columns, rest in JSON data.
 * Uses #[Publishable] without PublishableTrait; SoftDeletableTrait adds trash support.
 */
#[ORM\Entity(repositoryClass: NodeRepository::class)]
#[ORM\Table(name: 'cp_nodes')]
#[ORM\Index(columns: ['type'], name: 'idx_node_type')]
#[ORM\Index(columns: ['status'], name: 'idx_node_status')]
#[ORM\Index(columns: ['locale'], name: 'idx_node_locale')]
#[ORM\Index(columns: ['moderation_state'], name: 'idx_node_moderation_state')]
#[ORM\Index(columns: ['type', 'locale', 'status', 'published_at'], name: 'idx_node_type_locale_status_published')]
#[ORM\UniqueConstraint(name: 'uniq_node_slug_locale', columns: ['slug', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_node_translation_group_locale', columns: ['translation_group_id', 'locale'])]
#[Publishable(defaultStatus: Node::STATUS_DRAFT)]
#[SoftDeletable]
#[CpEntityType(id: 'node', label: 'entity.type.node', bundleable: true, revisionable: true, translatable: true)]
class Node implements OwnableInterface, TranslatableInterface, FieldableInterface
{
    use SoftDeletableTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SCHEDULED = 'scheduled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * Slug is unique per locale (uniq_node_slug_locale), not globally.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    /**
     * Content type string (page, post, portfolio, …) so modules can add types without core changes.
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Editorial workflow place (Content Moderation). Null = moderation not enabled
     * for this content type; kept separate from $status (publication).
     */
    #[ORM\Column(name: 'moderation_state', type: 'string', length: 32, nullable: true)]
    private ?string $moderationState = null;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    /**
     * Shared UUID linking translation siblings (not an FK). Nullable for ungrouped nodes.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $translationGroupId;

    /**
     * Dynamic fields (body, SEO, module meta). Filter/sort hot fields via columns, not JSON.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    /**
     * Nullable author; null ownerId means .own capabilities fail-safe to deny.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Term $category = null;

    /**
     * Many-to-many category terms (join table), separate from primary $category for cheap queries.
     *
     * @var Collection<int, Term>
     */
    #[ORM\ManyToMany(targetEntity: Term::class)]
    #[ORM\JoinTable(name: 'cp_node_categories')]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'category_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $categories;

    /**
     * Flat tag terms (vocabulary blog_tag).
     *
     * @var Collection<int, Term>
     */
    #[ORM\ManyToMany(targetEntity: Term::class)]
    #[ORM\JoinTable(name: 'cp_node_tags')]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * @param Uuid|null $translationGroupId existing group UUID, new Uuid::v7(), or null
     */
    public function __construct(string $title, string $slug, string $type, string $locale, ?Uuid $translationGroupId = null)
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->type = $type;
        $this->locale = $locale;
        $this->translationGroupId = $translationGroupId;
        $this->categories = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getModerationState(): ?string
    {
        return $this->moderationState;
    }

    public function setModerationState(?string $moderationState): static
    {
        $this->moderationState = $moderationState;
        $this->touch();

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTranslationGroupId(): ?Uuid
    {
        return $this->translationGroupId;
    }

    /**
     * Assigns this node to a new translation group (overwrites any existing group).
     */
    public function assignToNewTranslationGroup(): static
    {
        $this->translationGroupId = Uuid::v7();
        $this->touch();

        return $this;
    }

    /**
     * Joins an existing translation group (e.g. add EN to an existing TR page group).
     */
    public function joinTranslationGroup(Uuid $translationGroupId): static
    {
        $this->translationGroupId = $translationGroupId;
        $this->touch();

        return $this;
    }

    /**
     * Phase 3: removes this node from its translation group; siblings unchanged.
     */
    public function leaveTranslationGroup(): static
    {
        $this->translationGroupId = null;
        $this->touch();

        return $this;
    }

    /**
     * Phase 3: returns existing group UUID or creates one if missing.
     */
    public function ensureTranslationGroup(): Uuid
    {
        if (!$this->translationGroupId instanceof Uuid) {
            $this->assignToNewTranslationGroup();
        }

        /** @var Uuid $translationGroupId assignToNewTranslationGroup() always sets this */
        $translationGroupId = $this->translationGroupId;

        return $translationGroupId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        $this->touch();

        return $this;
    }

    public function getDataValue(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function setDataValue(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        $this->touch();

        return $this;
    }

    /**
     * FieldableInterface: the Field API binds definitions to Node::type and stores
     * values in the same $data bag, leaving non-field keys untouched.
     */
    public function fieldableEntityTypeId(): string
    {
        return 'node';
    }

    public function fieldableBundle(): string
    {
        return $this->type;
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        $this->touch();

        return $this;
    }

    /**
     * OwnableInterface: owner id for .own capability checks.
     */
    public function getOwnerId(): ?int
    {
        return $this->author?->getId();
    }

    public function getCategory(): ?Term
    {
        return $this->category;
    }

    public function setCategory(?Term $category): static
    {
        $this->category = $category;
        $this->touch();

        return $this;
    }

    /**
     * @return Collection<int, Term>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Term $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $this->touch();
        }

        return $this;
    }

    public function removeCategory(Term $category): static
    {
        if ($this->categories->removeElement($category)) {
            $this->touch();
        }

        return $this;
    }

    /**
     * @return Collection<int, Term>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Term $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $this->touch();
        }

        return $this;
    }

    public function removeTag(Term $tag): static
    {
        if ($this->tags->removeElement($tag)) {
            $this->touch();
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publish(?\DateTimeImmutable $at = null): static
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = $at ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
