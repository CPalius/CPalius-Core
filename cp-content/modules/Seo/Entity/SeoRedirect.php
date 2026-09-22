<?php

declare(strict_types=1);

namespace Modules\Seo\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Seo\Repository\SeoRedirectRepository;

/**
 * Operator-owned 301/302 from a dead public path to a live URL.
 *
 * Matched on 404 only (SeoRedirectSubscriber). Source is the path without a
 * leading slash; target is either a site-relative path or an http(s) URL.
 */
#[ORM\Entity(repositoryClass: SeoRedirectRepository::class)]
#[ORM\Table(name: 'cp_seo_redirects')]
#[ORM\UniqueConstraint(name: 'uniq_seo_redirect_source', columns: ['source_path'])]
#[ORM\Index(columns: ['is_active'], name: 'idx_seo_redirect_active')]
class SeoRedirect
{
    public const STATUS_PERMANENT = 301;
    public const STATUS_TEMPORARY = 302;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'source_path', type: 'string', length: 255)]
    private string $sourcePath;

    #[ORM\Column(name: 'target_url', type: 'string', length: 500)]
    private string $targetUrl;

    #[ORM\Column(name: 'status_code', type: 'smallint')]
    private int $statusCode = self::STATUS_PERMANENT;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'hit_count', type: 'integer')]
    private int $hitCount = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $sourcePath, string $targetUrl, int $statusCode = self::STATUS_PERMANENT)
    {
        $this->sourcePath = $sourcePath;
        $this->targetUrl = $targetUrl;
        $this->statusCode = $statusCode;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function setSourcePath(string $sourcePath): static
    {
        $this->sourcePath = $sourcePath;
        $this->touch();

        return $this;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function setTargetUrl(string $targetUrl): static
    {
        $this->targetUrl = $targetUrl;
        $this->touch();

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;
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

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function recordHit(): void
    {
        ++$this->hitCount;
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
