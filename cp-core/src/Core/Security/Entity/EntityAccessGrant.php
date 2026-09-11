<?php

declare(strict_types=1);

namespace App\Core\Security\Entity;

use App\Core\Security\Repository\EntityAccessGrantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Law 6.2 extended to row level: "this subject may do this capability on this
 * one record" — one unified capability namespace, not a parallel realm/gid
 * permission system. A record is reachable when the caller holds the global
 * `{capability}.any`, OR `{capability}.own` AND owns it (QueryScopeApplier),
 * OR an explicit grant row like this one matches — computed as a single SQL
 * EXISTS, never a per-row PHP voter (see EntityAccessManager).
 *
 * entity_type is an #[CpEntityType] id ("node", "taxonomy_term", …) or a
 * #[CpResource] name; entity_id is that row's primary key. Grants outlive
 * nothing automatically — a hard-delete caller must call
 * EntityAccessManager::revokeAllForEntity().
 */
#[ORM\Entity(repositoryClass: EntityAccessGrantRepository::class)]
#[ORM\Table(name: 'cp_entity_access_grants')]
#[ORM\Index(columns: ['entity_type', 'entity_id', 'capability'], name: 'idx_grant_entity_capability')]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'idx_grant_subject')]
#[ORM\UniqueConstraint(name: 'uniq_grant', columns: ['entity_type', 'entity_id', 'capability', 'subject_type', 'subject_id'])]
class EntityAccessGrant
{
    public const SUBJECT_USER = 'user';
    public const SUBJECT_ROLE = 'role';
    public const SUBJECT_ANY = 'any';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'entity_type', type: 'string', length: 64)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', type: 'integer')]
    private int $entityId;

    /**
     * The exact capability string this grant satisfies (e.g. "node.post.edit") —
     * the same namespace CapabilityRegistry / RoleConfigManager use everywhere else.
     */
    #[ORM\Column(type: 'string', length: 150)]
    private string $capability;

    #[ORM\Column(name: 'subject_type', type: 'string', length: 10)]
    private string $subjectType;

    /**
     * User id, role id, or '' when subjectType is SUBJECT_ANY. Never null —
     * keeps the composite unique constraint simple (no NULL-is-distinct surprises).
     */
    #[ORM\Column(name: 'subject_id', type: 'string', length: 64)]
    private string $subjectId;

    #[ORM\Column(name: 'granted_by', type: 'integer', nullable: true)]
    private ?int $grantedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $entityType,
        int $entityId,
        string $capability,
        string $subjectType,
        string $subjectId,
        ?int $grantedBy = null,
    ) {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->capability = $capability;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->grantedBy = $grantedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getGrantedBy(): ?int
    {
        return $this->grantedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
