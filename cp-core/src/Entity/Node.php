<?php

namespace App\Entity;

use App\Core\Annotation\Publishable;
use App\Core\Annotation\SoftDeletable;
use App\Core\Database\Traits\SoftDeletableTrait;
use App\Core\Security\OwnableInterface;
use App\Repository\NodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Botble'ın posts/pages gibi ayrı tablolara bölünmüş içerik modeli yerine,
 * Cotonti'nin tek tablo yaklaşımından ilham alan ama JSON ile genişletilmiş
 * "hibrit" model: sık sorgulanan alanlar (title, slug, status, locale) sabit
 * kolon, geri kalan her şey (gövde metni, SEO alanları, galeri vb.) `data`
 * JSON kolonunda tutulur. Böylece yeni bir içerik tipi ('portfolio' gibi)
 * eklemek için migration gerekmez.
 *
 * #[Publishable]: Node kendi status/publishedAt alanlarını ZATEN elle
 * tanımlıyordu (aşağıdaki property'lere bakın) ve touch()/updatedAt ile
 * entegre, daha zengin bir publish() implementasyonuna sahip — bu yüzden
 * PublishableTrait KULLANILMAZ (çakışır), sadece #[Publishable] attribute'u
 * eklenir ki ResourceRegistry Node'u "bu davranışı destekliyor" olarak
 * tanısın (bkz. ResourceRegistrationPass::detectBehaviors).
 *
 * #[SoftDeletable] + SoftDeletableTrait ise BİRE BİR entegre edildi:
 * deletedAt alanı önceden yoktu, "Çöp Kutusu" desteği bu adımda gerçek
 * anlamda kazanıldı.
 */
#[ORM\Entity(repositoryClass: NodeRepository::class)]
#[ORM\Table(name: 'nodes')]
#[ORM\Index(columns: ['type'], name: 'idx_node_type')]
#[ORM\Index(columns: ['status'], name: 'idx_node_status')]
#[ORM\Index(columns: ['locale'], name: 'idx_node_locale')]
#[ORM\UniqueConstraint(name: 'uniq_node_slug_locale', columns: ['slug', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_node_translation_group_locale', columns: ['translation_group_id', 'locale'])]
#[Publishable(defaultStatus: Node::STATUS_DRAFT)]
#[SoftDeletable]
class Node implements OwnableInterface
{
    use SoftDeletableTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SCHEDULED = 'scheduled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * Slug tek başına global-unique DEĞİLDİR; slug + locale birlikte
     * unique'tir (bkz. uniq_node_slug_locale). Böylece "/tr/hakkimizda"
     * ve "/en/about-us" aynı translation_group_id'ye ait olsa bile
     * birbirinden bağımsız slug'lar taşıyabilir.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    /**
     * İçerik tipi: 'page', 'post', 'portfolio' vb. Bilinçli olarak bir
     * PHP enum DEĞİL, string kolon — modüllerin kendi tiplerini
     * (ör. Blog modülü 'post', Portfolio modülü 'portfolio') çekirdeği
     * değiştirmeden ekleyebilmesi için.
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    /**
     * Bir içeriğin tüm dil çevirilerini birbirine bağlayan mantıksal
     * kimlik. Foreign key DEĞİLDİR (kendine referans veren bir "master"
     * satır yoktur) — aynı UUID'yi taşıyan tüm Node satırları eşit
     * statüde birer çeviridir. Yeni bir içerik oluşturulurken ilk dil
     * için yeni bir UUID üretilir; sonraki diller aynı UUID'yi paylaşır.
     *
     * Nullable'dır: henüz hiçbir çeviri grubuna dahil edilmemiş (tekil,
     * çevirisi olmayan) içerikler için NULL bırakılabilir. NULL değerler
     * uniq_node_translation_group_locale kısıtını ihlal etmez (MySQL/
     * PostgreSQL, UNIQUE kısıtlarında birden fazla NULL'a izin verir),
     * yani birden çok "grupsuz" Node aynı locale'de var olabilir.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $translationGroupId;

    /**
     * İçeriğin tüm dinamik alanları: gövde metni, öne çıkan görsel yolu,
     * SEO başlığı/açıklaması, modüle özel galeri/meta alanları vb.
     * Sık filtrelenen/sıralanan bir alan burada DEĞİL, yukarıdaki sabit
     * kolonlarda tutulmalıdır (JSON içi sorgular pahalı ve indekslenemez).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    /**
     * İçeriğin yazarı. Nullable'dır: sistem tarafından üretilen veya
     * içe aktarılan içerikler sahipsiz olabilir — bu durumda
     * OwnableInterface::getOwnerId() null döner ve CPaliusVoter/
     * QueryScopeApplier'daki ".own" yetkileri fail-safe gereği bu
     * Node'u kimseye "kendi içeriğiymiş" gibi göstermez.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /**
     * Yukarıdaki tekil $category ("birincil kategori" — breadcrumb/URL için)
     * İLE ÇAKIŞMAZ: bu, WordPress tarzı çoklu kategori atamasını (checkbox
     * listesi) destekleyen İKİNCİL bir ilişkidir. Bilinçli olarak Node::data
     * JSON'unda DEĞİL, gerçek bir join table'da tutulur — "hangi postlar X
     * kategorisinde" sorgusu sık ve JOIN ile ucuz olmalı, JSON'da hiç
     * indekslenemezdi (Manifesto'nun hibrit model ruhuyla tutarlı).
     *
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'node_category')]
    private Collection $categories;

    /**
     * Category'nin aksine hiyerarşisiz, generic bir sınıflandırma (bkz.
     * Tag entity doc-block'u).
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'node_tag')]
    private Collection $tags;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * @param Uuid|null $translationGroupId Bir çeviri grubuna dahil etmek
     *   için var olan bir grup UUID'si verin. Bu içeriğin YENİ bir çeviri
     *   grubunun ilk dili olmasını istiyorsanız Uuid::v7() üretip geçin.
     *   Hiç geçmezseniz (null), içerik hiçbir çeviri grubuna dahil
     *   edilmez — ileride assignToNewTranslationGroup() veya
     *   joinTranslationGroup() ile sonradan gruplandırılabilir.
     */
    public function __construct(string $title, string $slug, string $type, string $locale, ?Uuid $translationGroupId = null)
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->type = $type;
        $this->locale = $locale;
        $this->translationGroupId = $translationGroupId;
        $this->categories = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTranslationGroupId(): ?Uuid
    {
        return $this->translationGroupId;
    }

    /**
     * Bu içeriği yeni ve boş bir çeviri grubuna dahil eder (bu içerik o
     * grubun ilk/tek dilidir). Halihazırda bir gruba dahilse üzerine yazar.
     */
    public function assignToNewTranslationGroup(): static
    {
        $this->translationGroupId = Uuid::v7();
        $this->touch();

        return $this;
    }

    /**
     * Bu içeriği VAR OLAN bir çeviri grubuna katar — ör. "Hakkımızda"nın
     * TR'si zaten bir gruba sahipken, yeni oluşturulan EN çevirisini o
     * gruba dahil etmek için kullanılır.
     */
    public function joinTranslationGroup(Uuid $translationGroupId): static
    {
        $this->translationGroupId = $translationGroupId;
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        $this->touch();

        return $this;
    }

    public function getDataValue(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function setDataValue(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        $this->touch();

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        $this->touch();

        return $this;
    }

    /**
     * OwnableInterface sözleşmesi: CPaliusVoter ve QueryScopeApplier'ın
     * "node.post.edit.own" gibi parametrik yetkileri çözebilmesi için.
     */
    public function getOwnerId(): ?int
    {
        return $this->author?->getId();
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;
        $this->touch();

        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $this->touch();
        }

        return $this;
    }

    public function removeCategory(Category $category): static
    {
        if ($this->categories->removeElement($category)) {
            $this->touch();
        }

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $this->touch();
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        if ($this->tags->removeElement($tag)) {
            $this->touch();
        }

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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publish(?\DateTimeImmutable $at = null): static
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = $at ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
