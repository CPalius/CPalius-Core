<?php

declare(strict_types=1);

namespace App\Core\PathAlias\Entity;

use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pattern per (entity_type, bundle) — e.g. node/post → "/blog/[node:created:Y]/[node:title]".
 * `entityType` defaults to "node" (v1's only real consumer, see PathAliasGenerator); the column
 * exists now so a second entity type (Term) doesn't need a schema change later — same reasoning
 * as EntityAccessGrant's entity_type column.
 */
#[ORM\Entity(repositoryClass: PathAliasPatternRepository::class)]
#[ORM\Table(name: 'cp_path_alias_patterns')]
#[ORM\UniqueConstraint(name: 'uniq_path_alias_pattern_type_bundle', columns: ['entity_type', 'bundle'])]
class PathAliasPattern
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'entity_type', type: 'string', length: 64)]
    private string $entityType;

    #[ORM\Column(type: 'string', length: 64)]
    private string $bundle;

    #[ORM\Column(type: 'string', length: 255)]
    private string $pattern;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $entityType, string $bundle, string $pattern)
    {
        $this->entityType = $entityType;
        $this->bundle = $bundle;
        $this->pattern = $pattern;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getBundle(): string
    {
        return $this->bundle;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function setPattern(string $pattern): static
    {
        $this->pattern = $pattern;
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        $this->touch();

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
