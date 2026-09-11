<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UrlAliasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Optional URL shortcuts (Drupal-style); 301 to canonical target via UrlAliasListener.
 * Target ids are not FKs — deleted targets fail-safe to 404 without breaking aliases.
 */
#[ORM\Entity(repositoryClass: UrlAliasRepository::class)]
#[ORM\Table(name: 'url_aliases')]
#[ORM\UniqueConstraint(name: 'uniq_url_alias_path_locale', columns: ['alias_path', 'locale'])]
#[ORM\Index(columns: ['is_active'], name: 'idx_url_alias_active')]
class UrlAlias
{
    public const TARGET_NODE = 'node';
    public const TARGET_CATEGORY = 'category';
    public const TARGET_ROUTE = 'route';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Normalized path without leading slash; matched by UrlAliasListener.
     */
    #[ORM\Column(name: 'alias_path', type: 'string', length: 255)]
    private string $aliasPath;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(name: 'target_type', type: 'string', length: 20)]
    private string $targetType;

    #[ORM\Column(name: 'target_node_id', type: 'integer', nullable: true)]
    private ?int $targetNodeId = null;

    #[ORM\Column(name: 'target_category_id', type: 'integer', nullable: true)]
    private ?int $targetCategoryId = null;

    #[ORM\Column(name: 'target_route_name', type: 'string', length: 100, nullable: true)]
    private ?string $targetRouteName = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $aliasPath, string $locale, string $targetType)
    {
        $this->aliasPath = $aliasPath;
        $this->locale = $locale;
        $this->targetType = $targetType;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAliasPath(): string
    {
        return $this->aliasPath;
    }

    public function setAliasPath(string $aliasPath): static
    {
        $this->aliasPath = $aliasPath;
        $this->touch();

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        $this->touch();

        return $this;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): static
    {
        $this->targetType = $targetType;
        $this->touch();

        return $this;
    }

    public function getTargetNodeId(): ?int
    {
        return $this->targetNodeId;
    }

    public function setTargetNodeId(?int $targetNodeId): static
    {
        $this->targetNodeId = $targetNodeId;
        $this->touch();

        return $this;
    }

    public function getTargetCategoryId(): ?int
    {
        return $this->targetCategoryId;
    }

    public function setTargetCategoryId(?int $targetCategoryId): static
    {
        $this->targetCategoryId = $targetCategoryId;
        $this->touch();

        return $this;
    }

    public function getTargetRouteName(): ?string
    {
        return $this->targetRouteName;
    }

    public function setTargetRouteName(?string $targetRouteName): static
    {
        $this->targetRouteName = $targetRouteName;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
