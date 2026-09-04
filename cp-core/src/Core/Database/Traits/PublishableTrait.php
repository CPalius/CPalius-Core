<?php

declare(strict_types=1);

namespace App\Core\Database\Traits;

use Doctrine\ORM\Mapping as ORM;

/**
 * status/publishedAt pair matching Node. The using class owns its status vocabulary; this trait does not.
 */
trait PublishableTrait
{
    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'draft';

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Set status to published and stamp publishedAt (now if omitted), same as Node::publish().
     */
    public function publish(?\DateTimeImmutable $at = null): static
    {
        $this->status = 'published';
        $this->publishedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
