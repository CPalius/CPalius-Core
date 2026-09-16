<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use App\Core\Annotation\CpResource;
use App\Core\Annotation\Publishable;
use App\Core\Annotation\SoftDeletable;
use App\Core\Database\Traits\SoftDeletableTrait;
use App\Core\Entity\Attribute\CpEntityType;
use App\Core\Entity\FieldableInterface;
use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Localization\Contract\TranslatableTrait;
use App\Core\Security\OwnableInterface;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Showcase\Repository\ShowcaseItemRepository;

/**
 * One entry in the showcase — a piece of software, a website for sale, a car, a
 * service. What an entry *is* comes from its ShowcaseType, which also decides
 * which custom fields it carries.
 *
 * Hybrid content model (Law 3.1): the attributes nearly every listing needs are
 * real columns so they can be filtered, sorted and summed, while everything the
 * type's owner invented lives in $data and is flattened into
 * cp_showcase_item_index when a field is marked queryable (Law 6.3).
 *
 * #[CpResource] is declared with no capabilities on purpose: the module's own
 * capabilities.yaml owns the .own/.any scoped names, and an empty list here keeps
 * the generic /aacp/resources CRUD screen closed while still enabling the audit
 * log and the "resource:showcase_item" entity-reference target other modules use.
 */
#[ORM\Entity(repositoryClass: ShowcaseItemRepository::class)]
#[ORM\Table(name: 'cp_showcase_items')]
#[ORM\UniqueConstraint(name: 'uniq_showcase_item_slug_locale', columns: ['slug', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_showcase_item_group_locale', columns: ['translation_group_id', 'locale'])]
#[ORM\Index(columns: ['type_id', 'locale', 'status', 'published_at'], name: 'idx_showcase_item_listing')]
#[ORM\Index(columns: ['owner_id', 'status'], name: 'idx_showcase_item_owner')]
#[ORM\Index(columns: ['featured', 'status'], name: 'idx_showcase_item_featured')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_showcase_item_deleted')]
#[Publishable(defaultStatus: ShowcaseItem::STATUS_DRAFT)]
#[SoftDeletable]
#[CpEntityType(id: 'showcase_item', label: 'showcase.entity.item', bundleable: true, translatable: true)]
#[CpResource(name: 'showcase_item', module: 'showcase', capabilities: [], auditable: true)]
class ShowcaseItem implements OwnableInterface, TranslatableInterface, FieldableInterface
{
    use SoftDeletableTrait;
    use TranslatableTrait;

    public const ENTITY_TYPE_ID = 'showcase_item';

    /** Owner is still writing it; nobody else can see it. */
    public const STATUS_DRAFT = 'draft';
    /** Submitted and waiting for a moderator. */
    public const STATUS_PENDING = 'pending';
    /** Visible on the site. */
    public const STATUS_PUBLISHED = 'published';
    /** A moderator refused it; the owner sees the reason and may resubmit. */
    public const STATUS_REJECTED = 'rejected';
    /** Retired by its owner; kept for the record, hidden from listings. */
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    public const PRICE_NONE = 'none';
    public const PRICE_FIXED = 'fixed';
    public const PRICE_STARTING = 'starting';
    public const PRICE_NEGOTIABLE = 'negotiable';
    public const PRICE_FREE = 'free';
    public const PRICE_SUBSCRIPTION = 'subscription';

    public const PRICE_MODES = [
        self::PRICE_NONE,
        self::PRICE_FIXED,
        self::PRICE_STARTING,
        self::PRICE_NEGOTIABLE,
        self::PRICE_FREE,
        self::PRICE_SUBSCRIPTION,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShowcaseType::class)]
    #[ORM\JoinColumn(name: 'type_id', referencedColumnName: 'id', nullable: false)]
    private ShowcaseType $type;

    /**
     * Null owner means the submitter's account was deleted. ".own" capabilities
     * fail closed on a null owner (see CPaliusVoter), which is the safe default.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    /** Unique per locale (uniq_showcase_item_slug_locale), never globally. */
    #[ORM\Column(type: 'string', length: 191)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 191)]
    private string $title;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    /** Text format id the body was written in; resolved through TextFormatRegistry on render. */
    #[ORM\Column(name: 'body_format', type: 'string', length: 32)]
    private string $bodyFormat = 'restricted';

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_DRAFT;

    /** Why a moderator rejected it — shown to the owner, never to visitors. */
    #[ORM\Column(name: 'moderation_note', type: 'string', length: 500, nullable: true)]
    private ?string $moderationNote = null;

    #[ORM\Column(type: 'boolean')]
    private bool $featured = false;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(name: 'price_currency', type: 'string', length: 3, nullable: true)]
    private ?string $priceCurrency = null;

    #[ORM\Column(name: 'price_mode', type: 'string', length: 16)]
    private string $priceMode = self::PRICE_NONE;

    #[ORM\Column(name: 'external_url', type: 'string', length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(name: 'demo_url', type: 'string', length: 500, nullable: true)]
    private ?string $demoUrl = null;

    #[ORM\Column(name: 'contact_email', type: 'string', length: 191, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(name: 'contact_phone', type: 'string', length: 40, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(name: 'cover_asset_id', type: 'integer', nullable: true)]
    private ?int $coverAssetId = null;

    #[ORM\Column(name: 'view_count', type: 'integer')]
    private int $viewCount = 0;

    #[ORM\Column(name: 'click_count', type: 'integer')]
    private int $clickCount = 0;

    #[ORM\Column(name: 'rating_sum', type: 'integer')]
    private int $ratingSum = 0;

    #[ORM\Column(name: 'rating_count', type: 'integer')]
    private int $ratingCount = 0;

    /**
     * Custom field values keyed by FieldDefinition name, plus module meta.
     * Written only through FieldValuePersister (Law 5.3 choke point).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    /** @var Collection<int, Term> */
    #[ORM\ManyToMany(targetEntity: Term::class)]
    #[ORM\JoinTable(name: 'cp_showcase_item_terms')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'term_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $terms;

    /** @var Collection<int, ShowcaseItemMedia> */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: ShowcaseItemMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['weight' => 'ASC', 'id' => 'ASC'])]
    private Collection $media;

    /** @var Collection<int, ShowcaseLink> */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: ShowcaseLink::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['weight' => 'ASC', 'id' => 'ASC'])]
    private Collection $links;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ShowcaseType $type, string $title, string $slug, string $locale)
    {
        $this->type = $type;
        $this->title = $title;
        $this->slug = $slug;
        $this->locale = $locale;
        $this->terms = new ArrayCollection();
        $this->media = new ArrayCollection();
        $this->links = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

        return $this->touch();
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this->touch();
    }

    public function getOwnerId(): ?int
    {
        return $this->owner?->getId();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = mb_substr($slug, 0, 191);

        return $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = mb_substr(trim(strip_tags($title)), 0, 191);

        return $this->touch();
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $summary = $summary !== null ? trim(strip_tags($summary)) : '';
        $this->summary = $summary !== '' ? mb_substr($summary, 0, 500) : null;

        return $this->touch();
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Expects already-sanitized HTML. ShowcaseItemManager runs RichTextSanitizer
     * before calling this; nothing else may write the body.
     */
    public function setBody(?string $body): static
    {
        $this->body = $body !== null && trim($body) !== '' ? $body : null;

        return $this->touch();
    }

    public function getBodyFormat(): string
    {
        return $this->bodyFormat;
    }

    public function setBodyFormat(string $bodyFormat): static
    {
        $this->bodyFormat = mb_substr($bodyFormat, 0, 32);

        return $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!\in_array($status, self::STATUSES, true)) {
            return $this;
        }

        $this->status = $status;

        if ($status === self::STATUS_PUBLISHED && !$this->publishedAt instanceof \DateTimeImmutable) {
            $this->publishedAt = new \DateTimeImmutable();
        }

        return $this->touch();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->deletedAt === null;
    }

    /**
     * Published and still inside its listing window. Expiry is optional; an item
     * without expiresAt never goes stale.
     */
    public function isVisible(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->isPublished()) {
            return false;
        }

        return !$this->expiresAt instanceof \DateTimeImmutable
            || $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }

    public function getModerationNote(): ?string
    {
        return $this->moderationNote;
    }

    public function setModerationNote(?string $note): static
    {
        $note = $note !== null ? trim(strip_tags($note)) : '';
        $this->moderationNote = $note !== '' ? mb_substr($note, 0, 500) : null;

        return $this->touch();
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): static
    {
        $this->featured = $featured;

        return $this->touch();
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price !== null && is_numeric($price) ? number_format((float) $price, 2, '.', '') : null;

        return $this->touch();
    }

    public function getPriceFloat(): ?float
    {
        return $this->price !== null ? (float) $this->price : null;
    }

    public function getPriceCurrency(): ?string
    {
        return $this->priceCurrency;
    }

    public function setPriceCurrency(?string $currency): static
    {
        $currency = strtoupper(trim((string) $currency));
        $this->priceCurrency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;

        return $this->touch();
    }

    public function getPriceMode(): string
    {
        return $this->priceMode;
    }

    public function setPriceMode(string $mode): static
    {
        $this->priceMode = \in_array($mode, self::PRICE_MODES, true) ? $mode : self::PRICE_NONE;

        return $this->touch();
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $url): static
    {
        $this->externalUrl = $url;

        return $this->touch();
    }

    public function getDemoUrl(): ?string
    {
        return $this->demoUrl;
    }

    public function setDemoUrl(?string $url): static
    {
        $this->demoUrl = $url;

        return $this->touch();
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $email): static
    {
        $email = trim((string) $email);
        $this->contactEmail = $email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) !== false
            ? mb_substr($email, 0, 191)
            : null;

        return $this->touch();
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $phone): static
    {
        $phone = trim(strip_tags((string) $phone));
        $this->contactPhone = $phone !== '' ? mb_substr($phone, 0, 40) : null;

        return $this->touch();
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $location = trim(strip_tags((string) $location));
        $this->location = $location !== '' ? mb_substr($location, 0, 191) : null;

        return $this->touch();
    }

    public function getCoverAssetId(): ?int
    {
        return $this->coverAssetId;
    }

    public function setCoverAssetId(?int $assetId): static
    {
        $this->coverAssetId = $assetId !== null && $assetId > 0 ? $assetId : null;

        return $this->touch();
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function getRatingCount(): int
    {
        return $this->ratingCount;
    }

    public function getRatingSum(): int
    {
        return $this->ratingSum;
    }

    /**
     * Mean rating rounded to one decimal, or null when nobody has rated yet —
     * a zero here would read as "rated badly" rather than "not rated".
     */
    public function getRatingAverage(): ?float
    {
        return $this->ratingCount > 0 ? round($this->ratingSum / $this->ratingCount, 1) : null;
    }

    /**
     * Replaces both aggregates at once; ShowcaseReviewService recomputes them
     * from the approved reviews rather than incrementing, so a deleted or
     * unpublished review cannot leave the average drifting.
     */
    public function setRatingAggregate(int $sum, int $count): static
    {
        $this->ratingSum = max(0, $sum);
        $this->ratingCount = max(0, $count);

        return $this->touch();
    }

    public function recordView(): static
    {
        ++$this->viewCount;

        return $this;
    }

    public function recordClick(): static
    {
        ++$this->clickCount;

        return $this;
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

    /**
     * @return Collection<int, Term>
     */
    public function getTerms(): Collection
    {
        return $this->terms;
    }

    public function addTerm(Term $term): static
    {
        if (!$this->terms->contains($term)) {
            $this->terms->add($term);
        }

        return $this->touch();
    }

    public function removeTerm(Term $term): static
    {
        $this->terms->removeElement($term);

        return $this->touch();
    }

    public function clearTerms(): static
    {
        $this->terms->clear();

        return $this->touch();
    }

    /**
     * @return Collection<int, ShowcaseItemMedia>
     */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function addMedia(ShowcaseItemMedia $media): static
    {
        if (!$this->media->contains($media)) {
            $this->media->add($media);
            $media->setItem($this);
        }

        return $this->touch();
    }

    public function removeMedia(ShowcaseItemMedia $media): static
    {
        $this->media->removeElement($media);

        return $this->touch();
    }

    /**
     * @return Collection<int, ShowcaseLink>
     */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function addLink(ShowcaseLink $link): static
    {
        if (!$this->links->contains($link)) {
            $this->links->add($link);
            $link->setItem($this);
        }

        return $this->touch();
    }

    public function removeLink(ShowcaseLink $link): static
    {
        $this->links->removeElement($link);

        return $this->touch();
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this->touch();
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

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

    public function fieldableEntityTypeId(): string
    {
        return self::ENTITY_TYPE_ID;
    }

    /**
     * The bundle is the TYPE, which is exactly what makes this module
     * multi-purpose: "showcase_vehicle" and "showcase_saas" are two separate
     * field schemas over the same table.
     */
    public function fieldableBundle(): string
    {
        return $this->type->fieldBundle();
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
