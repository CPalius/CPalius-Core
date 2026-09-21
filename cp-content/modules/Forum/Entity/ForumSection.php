<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Localization\Contract\TranslatableTrait;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumNodeType;
use Modules\Forum\ForumSectionType;
use Modules\Forum\Repository\ForumSectionRepository;

/**
 * CPalius Forum Engine node (cp_forum_node).
 * Three-level hierarchy (Division → Category → Subcategory) via sectionType; nodeType is the node kind.
 */
#[ORM\Entity(repositoryClass: ForumSectionRepository::class)]
#[ORM\Table(name: 'cp_forum_sections')]
#[ORM\UniqueConstraint(name: 'uniq_forum_section_code_locale', columns: ['code', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_forum_section_slug_locale', columns: ['slug', 'locale'])]
#[ORM\Index(columns: ['locale'], name: 'idx_forum_section_locale')]
#[ORM\Index(columns: ['node_type'], name: 'idx_forum_section_node_type')]
#[ORM\Index(columns: ['parent_path'], name: 'idx_forum_section_parent_path')]
#[ORM\UniqueConstraint(name: 'uniq_forum_section_translation_group_locale', columns: ['translation_group_id', 'locale'])]
class ForumSection implements TranslatableInterface
{
    // Phase 3: translation_group_id column + TranslatableTrait helpers.
    use TranslatableTrait;

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

    #[ORM\Column(name: 'node_type', type: 'string', length: 16, enumType: ForumNodeType::class)]
    private ForumNodeType $nodeType = ForumNodeType::Forum;

    #[ORM\Column(name: 'link_url', type: 'string', length: 500, nullable: true)]
    private ?string $linkUrl = null;

    #[ORM\Column(name: 'required_capability', type: 'string', length: 100, nullable: true)]
    private ?string $requiredCapability = null;

    #[ORM\Column(name: 'is_locked', type: 'boolean')]
    private bool $locked = false;

    #[ORM\Column(name: 'default_topic_sort', type: 'string', length: 32)]
    private string $defaultTopicSort = 'latest';

    #[ORM\Column(name: 'rules_html', type: 'text', nullable: true)]
    private ?string $rulesHtml = null;

    #[ORM\Column(name: 'access_secret_hash', type: 'string', length: 255, nullable: true)]
    private ?string $accessSecretHash = null;

    /**
     * Materialized path including self, e.g. `/1/5/12/`.
     * Empty only before the first persist/backfill; roll-up reads ancestorIds().
     */
    #[ORM\Column(name: 'parent_path', type: 'string', length: 255, options: ['default' => ''])]
    private string $parentPath = '';

    /** Visible (public) topic count for this node and its descendants. */
    #[ORM\Column(name: 'topic_count', type: 'integer')]
    private int $topicCount = 0;

    /** Visible (public) post count for this node and its descendants. */
    #[ORM\Column(name: 'post_count', type: 'integer')]
    private int $postCount = 0;

    #[ORM\Column(name: 'topic_count_held', type: 'integer', options: ['default' => 0])]
    private int $topicCountHeld = 0;

    #[ORM\Column(name: 'topic_count_deleted', type: 'integer', options: ['default' => 0])]
    private int $topicCountDeleted = 0;

    #[ORM\Column(name: 'post_count_held', type: 'integer', options: ['default' => 0])]
    private int $postCountHeld = 0;

    #[ORM\Column(name: 'post_count_deleted', type: 'integer', options: ['default' => 0])]
    private int $postCountDeleted = 0;

    #[ORM\Column(name: 'view_count', type: 'integer')]
    private int $viewCount = 0;

    #[ORM\Column(name: 'last_topic_id', type: 'integer', nullable: true)]
    private ?int $lastTopicId = null;

    #[ORM\Column(name: 'last_topic_title', type: 'string', length: 255, nullable: true)]
    private ?string $lastTopicTitle = null;

    #[ORM\Column(name: 'last_post_id', type: 'integer', nullable: true)]
    private ?int $lastPostId = null;

    #[ORM\Column(name: 'last_post_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPostAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_poster_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lastPoster = null;

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

    /** CPalius Forum Engine cp_forum_node.display_order */
    public function getDisplayOrder(): int
    {
        return $this->sortOrder;
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
        return $this->allowTopics && $this->nodeType !== ForumNodeType::Link;
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
        if ($this->nodeType !== ForumNodeType::Link) {
            $this->nodeType = ForumNodeType::fromSectionType($sectionType);
        }

        return $this;
    }

    public function getNodeType(): ForumNodeType
    {
        return $this->nodeType;
    }

    public function setNodeType(ForumNodeType $nodeType): static
    {
        $this->nodeType = $nodeType;
        if ($nodeType === ForumNodeType::Link) {
            $this->allowTopics = false;
            $this->isContainer = false;
        }

        return $this;
    }

    public function isLinkNode(): bool
    {
        return $this->nodeType === ForumNodeType::Link;
    }

    public function getLinkUrl(): ?string
    {
        return $this->linkUrl;
    }

    public function setLinkUrl(?string $linkUrl): static
    {
        $this->linkUrl = $linkUrl !== null && $linkUrl !== '' ? $linkUrl : null;

        return $this;
    }

    public function getRequiredCapability(): ?string
    {
        return $this->requiredCapability;
    }

    public function setRequiredCapability(?string $requiredCapability): static
    {
        $this->requiredCapability = $requiredCapability !== null && $requiredCapability !== ''
            ? $requiredCapability
            : null;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function getDefaultTopicSort(): string
    {
        return $this->defaultTopicSort;
    }

    public function setDefaultTopicSort(string $defaultTopicSort): static
    {
        $allowed = ['latest', 'created', 'title', 'replies', 'views'];
        $this->defaultTopicSort = \in_array($defaultTopicSort, $allowed, true) ? $defaultTopicSort : 'latest';

        return $this;
    }

    public function getRulesHtml(): ?string
    {
        return $this->rulesHtml;
    }

    public function setRulesHtml(?string $rulesHtml): static
    {
        $this->rulesHtml = $rulesHtml !== null && $rulesHtml !== '' ? $rulesHtml : null;

        return $this;
    }

    public function getAccessSecretHash(): ?string
    {
        return $this->accessSecretHash;
    }

    public function setAccessSecretHash(?string $accessSecretHash): static
    {
        $this->accessSecretHash = $accessSecretHash !== null && $accessSecretHash !== ''
            ? $accessSecretHash
            : null;

        return $this;
    }

    public function isPassworded(): bool
    {
        return $this->accessSecretHash !== null && $this->accessSecretHash !== '';
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

    public function getParentPath(): string
    {
        return $this->parentPath;
    }

    public function setParentPath(string $parentPath): static
    {
        $this->parentPath = $parentPath;

        return $this;
    }

    /**
     * Self-inclusive ancestor ids parsed from parent_path (`/1/5/12/` → [1, 5, 12]).
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        if ($this->parentPath === '') {
            return $this->id !== null ? [$this->id] : [];
        }

        $ids = [];
        foreach (explode('/', trim($this->parentPath, '/')) as $part) {
            if ($part !== '') {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }

    /** Visible (public) topics in this node and its descendants. */
    public function getTopicCount(): int
    {
        return $this->topicCount;
    }

    public function setTopicCount(int $topicCount): static
    {
        $this->topicCount = $topicCount;

        return $this;
    }

    /** CPalius Forum Engine cp_forum_node.thread_count */
    public function getThreadCount(): int
    {
        return $this->topicCount;
    }

    public function getTopicCountHeld(): int
    {
        return $this->topicCountHeld;
    }

    public function setTopicCountHeld(int $topicCountHeld): static
    {
        $this->topicCountHeld = max(0, $topicCountHeld);

        return $this;
    }

    public function getTopicCountDeleted(): int
    {
        return $this->topicCountDeleted;
    }

    public function setTopicCountDeleted(int $topicCountDeleted): static
    {
        $this->topicCountDeleted = max(0, $topicCountDeleted);

        return $this;
    }

    /** Visible (public) posts in this node and its descendants. */
    public function getPostCount(): int
    {
        return $this->postCount;
    }

    public function setPostCount(int $postCount): static
    {
        $this->postCount = $postCount;

        return $this;
    }

    public function getPostCountHeld(): int
    {
        return $this->postCountHeld;
    }

    public function setPostCountHeld(int $postCountHeld): static
    {
        $this->postCountHeld = max(0, $postCountHeld);

        return $this;
    }

    public function getPostCountDeleted(): int
    {
        return $this->postCountDeleted;
    }

    public function setPostCountDeleted(int $postCountDeleted): static
    {
        $this->postCountDeleted = max(0, $postCountDeleted);

        return $this;
    }

    /** CPalius Forum Engine cp_forum_node.message_count */
    public function getMessageCount(): int
    {
        return $this->postCount;
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

    public function setLastTopicTitle(?string $lastTopicTitle): static
    {
        $this->lastTopicTitle = $lastTopicTitle;

        return $this;
    }

    public function getLastTopicTitle(): ?string
    {
        return $this->lastTopicTitle;
    }

    public function getLastPostId(): ?int
    {
        return $this->lastPostId;
    }

    public function setLastPostId(?int $lastPostId): static
    {
        $this->lastPostId = $lastPostId;

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

    public function getLastPoster(): ?User
    {
        return $this->lastPoster;
    }

    public function setLastPoster(?User $lastPoster): static
    {
        $this->lastPoster = $lastPoster;

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
