<?php

declare(strict_types=1);

namespace Modules\Blog\Entity;

use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Blog\Repository\BlogCommentRepository;

/**
 * Blog post comment. Guests store name/email; members use the shared User profile.
 */
#[ORM\Entity(repositoryClass: BlogCommentRepository::class)]
#[ORM\Table(name: 'cp_blog_comments')]
#[ORM\Index(columns: ['node_id'], name: 'idx_blog_comment_node')]
#[ORM\Index(columns: ['status'], name: 'idx_blog_comment_status')]
#[ORM\Index(columns: ['parent_id'], name: 'idx_blog_comment_parent')]
#[ORM\Index(columns: ['author_id'], name: 'idx_blog_comment_author')]
#[ORM\Index(columns: ['node_id', 'status'], name: 'idx_blog_comment_node_status')]
class BlogComment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SPAM = 'spam';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Node::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'guest_name', type: 'string', length: 100, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(name: 'guest_email', type: 'string', length: 180, nullable: true)]
    private ?string $guestEmail = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'poster_ip', type: 'string', length: 64, nullable: true)]
    private ?string $posterIp = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Node $node, string $body)
    {
        $this->node = $node;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): Node
    {
        return $this->node;
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function setGuestName(?string $guestName): static
    {
        $this->guestName = $guestName;

        return $this;
    }

    public function getGuestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function setGuestEmail(?string $guestEmail): static
    {
        $this->guestEmail = $guestEmail;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPosterIp(): ?string
    {
        return $this->posterIp;
    }

    public function setPosterIp(?string $posterIp): static
    {
        $this->posterIp = $posterIp;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Public label: member username/name, else guest name. Never email.
     */
    public function getDisplayName(): string
    {
        if ($this->author instanceof User) {
            $label = $this->author->getPublicDisplayName();
            if ($label !== '') {
                return $label;
            }
        }

        $guest = trim((string) $this->guestName);

        return $guest !== '' ? $guest : '#';
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_SPAM,
            self::STATUS_REJECTED,
        ];
    }
}
