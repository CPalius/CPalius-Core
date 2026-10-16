<?php

declare(strict_types=1);

namespace App\Core\Trash\Entity;

use App\Core\Trash\Repository\TrashRuleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Retention policy for one resource type, edited in AACP → Recycle bin.
 *
 * The row named "*" is the global default: its enabled flag is the master
 * switch and its retention applies to every type that has no row of its own.
 */
#[ORM\Entity(repositoryClass: TrashRuleRepository::class)]
#[ORM\Table(name: 'cp_trash_rules')]
class TrashRule
{
    public const GLOBAL = '*';

    #[ORM\Id]
    #[ORM\Column(name: 'resource_name', type: 'string', length: 100)]
    private string $resourceName;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /** 0 = never expire automatically. */
    #[ORM\Column(name: 'retention_days', type: 'integer', options: ['default' => 30])]
    private int $retentionDays = 30;

    public function __construct(string $resourceName, bool $enabled = true, int $retentionDays = 30)
    {
        $this->resourceName = $resourceName;
        $this->enabled = $enabled;
        $this->retentionDays = max(0, $retentionDays);
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $retentionDays): static
    {
        $this->retentionDays = max(0, min(3650, $retentionDays));

        return $this;
    }
}
