<?php

declare(strict_types=1);

namespace App\Core\Audit\Entity;

use App\Core\Audit\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable change-history record written automatically for every entity
 * marked #[CpResource(auditable: true)] or #[Auditable] (see AuditLogListener).
 * Never edited or deleted through the application — append-only by design.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'cp_audit_logs')]
#[ORM\Index(columns: ['resource_name', 'resource_id'], name: 'idx_audit_resource')]
#[ORM\Index(columns: ['user_id'], name: 'idx_audit_user')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created_at')]
class AuditLog
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * The #[CpResource] short name (e.g. "vehicle") when the entity has one,
     * otherwise the entity's short class name (e.g. "Setting").
     */
    #[ORM\Column(name: 'resource_name', type: 'string', length: 100)]
    private string $resourceName;

    /**
     * Primary key of the affected row, as a string so composite and UUID
     * identifiers fit too. Null only for a create captured before flush.
     */
    #[ORM\Column(name: 'resource_id', type: 'string', length: 128, nullable: true)]
    private ?string $resourceId;

    #[ORM\Column(name: 'user_id', type: 'integer', nullable: true)]
    private ?int $userId;

    #[ORM\Column(type: 'string', length: 16)]
    private string $action;

    /**
     * Field-level diff as {field: [oldValue, newValue]}. Creates use
     * [null, newValue]; deletes use [oldValue, null].
     *
     * @var array<string, array{0: mixed, 1: mixed}>
     */
    #[ORM\Column(type: 'json')]
    private array $changes;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public function __construct(
        string $resourceName,
        ?string $resourceId,
        string $action,
        array $changes,
        ?int $userId,
    ) {
        $this->resourceName = $resourceName;
        $this->resourceId = $resourceId;
        $this->action = $action;
        $this->changes = $changes;
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function setResourceId(?string $resourceId): static
    {
        $this->resourceId = $resourceId;

        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
