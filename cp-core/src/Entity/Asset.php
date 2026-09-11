<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * First-class media entity (Pimcore-inspired), shared across nodes via id refs in Node::data.
 * No direct Node relation here — assets stay independent of content modules.
 */
#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Table(name: 'assets')]
#[ORM\Index(columns: ['hash'], name: 'idx_asset_hash')]
#[ORM\Index(columns: ['mime_type'], name: 'idx_asset_mime_type')]
class Asset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Storage filename (often hash-based), not the original upload name.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    /**
     * Original upload filename for display/download only.
     */
    #[ORM\Column(name: 'original_name', type: 'string', length: 255)]
    private string $originalName;

    /**
     * Directory relative to storage root (e.g. "2026/07/"), not public/uploads prefix.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $path;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 100)]
    private string $mimeType;

    #[ORM\Column(name: 'file_size', type: 'integer')]
    private int $fileSize;

    /**
     * sha256 content hash for deduplication (AssetRepository::findOneByHash).
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $hash;

    /**
     * Extra metadata (dimensions, alt text, EXIF). Hot fields stay in columns.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $filename,
        string $originalName,
        string $path,
        string $mimeType,
        int $fileSize,
        string $hash,
    ) {
        $this->filename = $filename;
        $this->originalName = $originalName;
        $this->path = $path;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->hash = $hash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Full storage key for Flysystem read/write (path + filename).
     */
    public function getStorageKey(): string
    {
        return rtrim($this->path, '/').'/'.$this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadataValue(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
