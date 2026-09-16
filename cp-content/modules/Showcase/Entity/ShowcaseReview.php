<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use App\Core\Security\OwnableInterface;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A member's rating and note on one item. One review per (item, member) —
 * enforced in the database, not only in the service, so a double submit races
 * into a constraint violation instead of a second row.
 */
#[ORM\Entity(repositoryClass: \Modules\Showcase\Repository\ShowcaseReviewRepository::class)]
#[ORM\Table(name: 'cp_showcase_reviews')]
#[ORM\UniqueConstraint(name: 'uniq_showcase_review_item_author', columns: ['item_id', 'author_id'])]
#[ORM\Index(columns: ['item_id', 'status'], name: 'idx_showcase_review_item_status')]
class ShowcaseReview implements OwnableInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShowcaseItem::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShowcaseItem $item;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $author = null;

    #[ORM\Column(type: 'smallint')]
    private int $rating;

    #[ORM\Column(type: 'string', length: 2000, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ShowcaseItem $item, ?User $author, int $rating)
    {
        $this->item = $item;
        $this->author = $author;
        $this->rating = max(self::MIN_RATING, min(self::MAX_RATING, $rating));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ShowcaseItem
    {
        return $this->item;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getOwnerId(): ?int
    {
        return $this->author?->getId();
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): static
    {
        $this->rating = max(self::MIN_RATING, min(self::MAX_RATING, $rating));

        return $this->touch();
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Reviews are plain text on purpose: a rating note is not worth a rich-text
     * sanitizer pass, and stripping tags here removes the whole markup attack
     * surface instead of narrowing it.
     */
    public function setBody(?string $body): static
    {
        $body = trim(strip_tags((string) $body));
        $this->body = $body !== '' ? mb_substr($body, 0, 2000) : null;

        return $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (\in_array($status, self::STATUSES, true)) {
            $this->status = $status;
            $this->touch();
        }

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
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
