<?php

declare(strict_types=1);

namespace App\Core\Migrate\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row of the migration map (cp_migration_map).
 *
 * The unique constraint on (migration_id, source_id) is the whole guarantee:
 * the database, not the runner's bookkeeping, is what prevents a row being
 * imported twice. A concurrent second run loses the insert race instead of
 * duplicating content.
 *
 * source_id is a string because source systems disagree about what a key is —
 * WordPress uses integers, CSV files use anything, and a CRM export may key on
 * an email address. Hashing it away would make the map unreadable exactly when
 * someone needs to read it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_migration_map')]
#[ORM\UniqueConstraint(name: 'uniq_migration_source', columns: ['migration_id', 'source_id'])]
#[ORM\Index(columns: ['migration_id'], name: 'idx_migration_map_migration')]
class MigrationMapEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'migration_id', type: 'string', length: 128)]
    private string $migrationId;

    #[ORM\Column(name: 'source_id', type: 'string', length: 191)]
    private string $sourceId;

    /**
     * Checksum of the transformed payload, so a re-run can tell an unchanged
     * row from one that was edited at the source.
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $checksum;

    #[ORM\Column(name: 'destination_type', type: 'string', length: 64)]
    private string $destinationType;

    #[ORM\Column(name: 'destination_id', type: 'string', length: 191)]
    private string $destinationId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $migrationId,
        string $sourceId,
        string $checksum,
        string $destinationType,
        string $destinationId,
    ) {
        $this->migrationId = $migrationId;
        $this->sourceId = $sourceId;
        $this->checksum = $checksum;
        $this->destinationType = $destinationType;
        $this->destinationId = $destinationId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMigrationId(): string
    {
        return $this->migrationId;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getDestinationType(): string
    {
        return $this->destinationType;
    }

    public function getDestinationId(): string
    {
        return $this->destinationId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function refresh(string $checksum, string $destinationType, string $destinationId): void
    {
        $this->checksum = $checksum;
        $this->destinationType = $destinationType;
        $this->destinationId = $destinationId;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
