<?php

declare(strict_types=1);

namespace App\Core\Security\Entity;

use App\Core\Security\Repository\UserCapabilityOverrideRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sparse per-user overlay on top of role YAML. Absence means inherit.
 * CPaliusVoter is the only reader that turns these rows into a decision.
 */
#[ORM\Entity(repositoryClass: UserCapabilityOverrideRepository::class)]
#[ORM\Table(name: 'cp_user_capability_overrides')]
#[ORM\UniqueConstraint(name: 'uniq_user_capability_override', columns: ['user_id', 'capability'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_capability_override_user')]
class UserCapabilityOverride
{
    public const EFFECT_GRANT = 'grant';
    public const EFFECT_DENY = 'deny';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: 'integer')]
    private int $userId;

    #[ORM\Column(type: 'string', length: 150)]
    private string $capability;

    #[ORM\Column(type: 'string', length: 8)]
    private string $effect;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId, string $capability, string $effect)
    {
        $this->userId = $userId;
        $this->capability = $capability;
        $this->effect = $effect;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function getEffect(): string
    {
        return $this->effect;
    }

    public function setEffect(string $effect): void
    {
        $this->effect = $effect;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
