<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumSectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumSectionType;

/**
 * Forum bölümü — Cotonti'nin structure_area='forums' hiyerarşisinin
 * CPalius karşılığı. Üst düzey kayıtlar (isContainer=true) yalnızca
 * gruplama yapar; konu açılabilen yaprak bölümlerde allowTopics=true olur.
 */
#[ORM\Entity(repositoryClass: ForumSectionRepository::class)]
#[ORM\Table(name: 'forum_sections')]
#[ORM\UniqueConstraint(name: 'uniq_forum_section_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_forum_section_slug_locale', columns: ['slug', 'locale'])]
#[ORM\Index(columns: ['locale'], name: 'idx_forum_section_locale')]
class ForumSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $icon = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'title' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_container', type: 'boolean')]
    private bool $isContainer = false;

    #[ORM\Column(name: 'allow_topics', type: 'boolean')]
    private bool $allowTopics = true;

    #[ORM\Column(name: 'section_type', type: 'string', length: 32, enumType: ForumSectionType::class)]
    private ForumSectionType $sectionType = ForumSectionType::Subcategory;

    #[ORM\Column(name: 'topic_count', type: 'integer')]
    private int $topicCount = 0;

    #[ORM\Column(name: 'post_count', type: 'integer')]
    private int $postCount = 0;

    #[ORM\Column(name: 'view_count', type: 'integer')]
    private int $viewCount = 0;

    #[ORM\Column(name: 'last_topic_id', type: 'integer', nullable: true)]
    private ?int $lastTopicId = null;

    #[ORM\Column(name: 'last_topic_title', type: 'string', length: 255, nullable: true)]
    private ?string $lastTopicTitle = null;

    #[ORM\Column(name: 'last_post_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPostAt = null;

    #[ORM\Column(name: 'last_poster_name', type: 'string', length: 100, nullable: true)]
    private ?string $lastPosterName = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $slug, string $locale, string $title)
    {
        $this->code = $code;
        $this->slug = $slug;
        $this->locale = $locale;
        $this->title = $title;
        $this->children = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
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

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
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

    public function isContainer(): bool
    {
        return $this->isContainer;
    }

    public function setIsContainer(bool $isContainer): static
    {
        $this->isContainer = $isContainer;

        return $this;
    }

    public function allowsTopics(): bool
    {
        return $this->allowTopics;
    }

    public function setAllowTopics(bool $allowTopics): static
    {
        $this->allowTopics = $allowTopics;

        return $this;
    }

    public function getSectionType(): ForumSectionType
    {
        return $this->sectionType;
    }

    public function setSectionType(ForumSectionType $sectionType): static
    {
        $this->sectionType = $sectionType;
        $this->isContainer = $sectionType->isContainer();
        $this->allowTopics = $sectionType->allowsTopics();

        return $this;
    }

    public function isDivision(): bool
    {
        return $this->sectionType === ForumSectionType::Division;
    }

    public function isCategory(): bool
    {
        return $this->sectionType === ForumSectionType::Category;
    }

    public function isSubcategory(): bool
    {
        return $this->sectionType === ForumSectionType::Subcategory;
    }

    public function getTopicCount(): int
    {
        return $this->topicCount;
    }

    public function setTopicCount(int $topicCount): static
    {
        $this->topicCount = $topicCount;

        return $this;
    }

    public function getPostCount(): int
    {
        return $this->postCount;
    }

    public function setPostCount(int $postCount): static
    {
        $this->postCount = $postCount;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function setViewCount(int $viewCount): static
    {
        $this->viewCount = $viewCount;

        return $this;
    }

    public function getLastTopicId(): ?int
    {
        return $this->lastTopicId;
    }

    public function setLastTopicId(?int $lastTopicId): static
    {
        $this->lastTopicId = $lastTopicId;

        return $this;
    }

    public function getLastTopicTitle(): ?string
    {
        return $this->lastTopicTitle;
    }

    public function setLastTopicTitle(?string $lastTopicTitle): static
    {
        $this->lastTopicTitle = $lastTopicTitle;

        return $this;
    }

    public function getLastPostAt(): ?\DateTimeImmutable
    {
        return $this->lastPostAt;
    }

    public function setLastPostAt(?\DateTimeImmutable $lastPostAt): static
    {
        $this->lastPostAt = $lastPostAt;

        return $this;
    }

    public function getLastPosterName(): ?string
    {
        return $this->lastPosterName;
    }

    public function setLastPosterName(?string $lastPosterName): static
    {
        $this->lastPosterName = $lastPosterName;

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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
