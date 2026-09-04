<?php

declare(strict_types=1);

namespace App\Core\Database\Traits;

use Doctrine\ORM\Mapping as ORM;

/**
 * Soft-delete via deletedAt. Does not hide rows from queries — that belongs on a Doctrine filter / repository.
 */
trait SoftDeletableTrait
{
    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Move to the recycle bin. Defaults to now.
     */
    public function softDelete(?\DateTimeImmutable $at = null): static
    {
        $this->deletedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Restore from the recycle bin.
     */
    public function restore(): static
    {
        $this->deletedAt = null;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
