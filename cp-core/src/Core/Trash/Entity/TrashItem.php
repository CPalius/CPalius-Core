<?php

declare(strict_types=1);

namespace App\Core\Trash\Entity;

use App\Core\Trash\Repository\TrashItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One deleted database row, kept verbatim so it can be put back.
 *
 * The row is stored as raw column => value pairs read straight from the table
 * just before the delete reached it, not as an entity snapshot. That is what
 * makes restore independent of the entity class: a renamed property or a
 * module that has since been disabled does not stop the row going back.
 *
 * Rows deleted by the same flush share a batch_id so "restore" can bring a
 * parent and the children Doctrine removed with it back together.
 */
#[ORM\Entity(repositoryClass: TrashItemRepository::class)]
#[ORM\Table(name: 'cp_trash_items')]
#[ORM\Index(columns: ['resource_name'], name: 'idx_trash_resource')]
#[ORM\Index(columns: ['batch_id'], name: 'idx_trash_batch')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_trash_expires')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_trash_deleted_at')]
class TrashItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'batch_id', type: 'string', length: 32)]
    private string $batchId;

    /** Human-facing type name (#[CpResource] name or short class name). */
    #[ORM\Column(name: 'resource_name', type: 'string', length: 100)]
    private string $resourceName;

    #[ORM\Column(name: 'entity_class', type: 'string', length: 255)]
    private string $entityClass;

    #[ORM\Column(name: 'table_name', type: 'string', length: 128)]
    private string $tableName;

    #[ORM\Column(name: 'resource_id', type: 'string', length: 128, nullable: true)]
    private ?string $resourceId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $label;

    /**
     * Raw row, {column: value}. Binary values are wrapped as {"__b64": "..."}
     * by TrashRowCodec so the JSON column stays valid UTF-8.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'row_data', type: 'json')]
    private array $rowData;

    #[ORM\Column(name: 'deleted_by', type: 'integer', nullable: true)]
    private ?int $deletedBy;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $deletedAt;

    /** Null = keep until a human empties it. */
    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    /**
     * @param array<string, mixed> $rowData
     */
    public function __construct(
        string $batchId,
        string $resourceName,
        string $entityClass,
        string $tableName,
        ?string $resourceId,
        string $label,
        array $rowData,
        ?int $deletedBy,
        ?\DateTimeImmutable $expiresAt,
    ) {
        $this->batchId = $batchId;
        $this->resourceName = $resourceName;
        $this->entityClass = $entityClass;
        $this->tableName = $tableName;
        $this->resourceId = $resourceId;
        $this->label = mb_substr($label, 0, 255);
        $this->rowData = $rowData;
        $this->deletedBy = $deletedBy;
        $this->expiresAt = $expiresAt;
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRowData(): array
    {
        return $this->rowData;
    }

    public function getDeletedBy(): ?int
    {
        return $this->deletedBy;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
