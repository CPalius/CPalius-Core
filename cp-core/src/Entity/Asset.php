<?php

namespace App\Entity;

use App\Repository\AssetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Medya (görsel, PDF, video vb.) için 1. sınıf bir vatandaş — Node'un bir
 * parçası (JSON içi bir alan) DEĞİLDİR. Bunun nedeni: aynı dosyanın birden
 * çok Node tarafından paylaşılabilmesi (ör. aynı görsel hem bir ürün hem
 * bir blog yazısında kullanılsın), kendi metadata'sına (boyut, alt etiket)
 * ve ileride kendi versiyon geçmişine sahip olabilmesidir — Pimcore'un
 * Asset modelinden ilham alınmıştır.
 *
 * Node <-> Asset ilişkisi bilinçli olarak burada TANIMLANMAZ; hangi
 * Node'un hangi Asset'i (ör. "featured_image") kullandığı Node::data
 * JSON'unda bir Asset ID'si referansı olarak tutulur. Böylece Asset,
 * Node modülüne bağımlı olmadan bağımsız yaşayabilir.
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
     * Depoda fiziksel olarak saklanan dosya adı (ör. hash tabanlı
     * "5fa2d3....jpg") — çakışmaları önlemek için orijinal ad DEĞİLDİR.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    /**
     * Kullanıcının yüklediği orijinal dosya adı (ör. "manzara.jpg") —
     * yalnızca görüntüleme/indirme amaçlı, depolamada kullanılmaz.
     */
    #[ORM\Column(name: 'original_name', type: 'string', length: 255)]
    private string $originalName;

    /**
     * cpalius_storage disk köküne göre göreli dizin (ör. "uploads/2026/07/"
     * değil — bu path zaten storage'ın kendi kökü olan public/uploads
     * altına görelidir, ör. "2026/07/").
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $path;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 100)]
    private string $mimeType;

    #[ORM\Column(name: 'file_size', type: 'integer')]
    private int $fileSize;

    /**
     * Dosya içeriğinin sha256 hash'i. Aynı dosyanın tekrar tekrar
     * yüklenmesini önlemek için AssetManager::upload() bu hash'e göre
     * var olan bir Asset'i arar (bkz. AssetRepository::findOneByHash).
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $hash;

    /**
     * Görsel genişliği/yüksekliği, alt etiketi, EXIF verisi gibi dinamik
     * meta veriler. Node::data ile aynı hibrit felsefe: sık filtrelenen
     * alanlar (hash, mimeType) sabit kolon, geri kalan JSON'da.
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
     * Storage kökünden dosyaya giden tam göreli yol (ör. "2026/07/5fa2d3....jpg")
     * — Flysystem'e read/write için verilecek anahtar budur.
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
