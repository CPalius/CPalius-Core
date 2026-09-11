<?php

declare(strict_types=1);

namespace App\Core\Revision\Entity;

use App\Core\Revision\Repository\NodeRevisionRepository;
use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * An immutable snapshot of a Node's editorial state (title, slug, status, data,
 * publish time, taxonomy). One row per saved change; the newest is "current".
 */
#[ORM\Entity(repositoryClass: NodeRevisionRepository::class)]
#[ORM\Table(name: 'cp_node_revisions')]
#[ORM\Index(columns: ['node_id', 'created_at'], name: 'idx_node_revision_node_created')]
class NodeRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Node::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    /** Denormalized for the revision list (the node title may have changed since). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /** @var array<string, mixed> Editorial state — see NodeSnapshot. */
    #[ORM\Column(type: 'json')]
    private array $snapshot;

    /** sha256 of the canonical snapshot, for cheap redundancy checks. */
    #[ORM\Column(name: 'snapshot_hash', type: 'string', length: 64)]
    private string $snapshotHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(name: 'log_message', type: 'string', length: 500, nullable: true)]
    private ?string $logMessage;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(Node $node, string $title, array $snapshot, string $snapshotHash, ?User $author = null, ?string $logMessage = null)
    {
        $this->node = $node;
        $this->title = mb_substr($title, 0, 255);
        $this->snapshot = $snapshot;
        $this->snapshotHash = $snapshotHash;
        $this->author = $author;
        $this->logMessage = $logMessage !== null && trim($logMessage) !== '' ? mb_substr(trim($logMessage), 0, 500) : null;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getSnapshotHash(): string
    {
        return $this->snapshotHash;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getLogMessage(): ?string
    {
        return $this->logMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
