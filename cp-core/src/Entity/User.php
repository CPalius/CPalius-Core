<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * CPalius'ta yetkilendirme ROLE_* sabit kontrolüyle DEĞİL, dinamik
 * yeteneklerle (capabilities) yapılır (bkz. CPaliusVoter). Bu yüzden
 * getRoles(), Symfony'nin firewall/is_granted altyapısının ihtiyaç
 * duyduğu ASGARİ "ROLE_USER" sabitini döner — gerçek yetki mantığı
 * $roles alanındaki CPalius rol kimlikleridir ("admin", "editor" vb.),
 * bunlar RoleConfigManager üzerinden cp-content/config/sync/ altındaki
 * YAML dosyalarına karşı çözülür.
 *
 * Node/Asset ile aynı hibrit felsefe: sık sorgulanan/filtrelenen alanlar
 * (email, status) sabit kolon, profil bilgileri gibi değişken alanlar
 * $data JSON kolonunda.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[ORM\Index(columns: ['status'], name: 'idx_user_status')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BANNED = 'banned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    /**
     * Opsiyonel kullanıcı adı — girişte e-postanın yanı sıra alternatif bir
     * kimlik olarak kullanılabilir (bkz. CpUserProvider::loadUserByIdentifier()).
     * getUserIdentifier() BİLİNÇLİ OLARAK hâlâ email döner (mevcut
     * session/remember-me davranışıyla geriye dönük uyumluluk); username
     * sadece GİRİŞ ANINDA hangi kullanıcının yükleneceğini bulmak için
     * ikincil bir arama anahtarıdır.
     */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * Bu kullanıcıya atanmış CPalius rol kimlikleri (ör. ["admin"],
     * ["editor", "support"]). Foreign key DEĞİLDİR: roller DB'de değil
     * cp-content/config/sync/user.role.*.yaml dosyalarında yaşar (bkz.
     * RoleConfigManager). Burada sadece rol kimliği string'leri tutulur;
     * bir rol config'i silinirse ilgili kimlik burada "sahipsiz" kalır
     * ve CPaliusVoter onu sessizce yok sayar (fail-safe).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * Ad, soyad, avatar, biyografi gibi profil bilgileri ve modüllerin
     * kullanıcıya eklediği diğer dinamik alanlar. Node::data ile aynı
     * mantık: sık filtrelenmeyen her şey burada.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Symfony'nin kullanıcıyı benzersiz tanımlamak için kullandığı
     * kimlik (session/remember-me/vs. içinde saklanır).
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): static
    {
        $this->password = $hashedPassword;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Symfony UserInterface sözleşmesi gereği vardır; gerçek yetki
     * kararları için KULLANILMAZ (bkz. sınıf üstü doküman). Sadece
     * firewall'ın "kimliği doğrulanmış kullanıcı" ayrımı yapabilmesi
     * için sabit bir taban rol döner.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * CPalius rol kimlikleri (RoleConfigManager + CPaliusVoter tarafından
     * kullanılır) — Symfony'nin ROLE_* mekanizmasıyla KARIŞTIRILMAMALIDIR.
     *
     * @return list<string>
     */
    public function getCpaliusRoles(): array
    {
        return $this->roles;
    }

    /**
     * @param list<string> $roles
     */
    public function setCpaliusRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function addCpaliusRole(string $role): static
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }

        return $this;
    }

    public function removeCpaliusRole(string $role): static
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            static fn (string $existing) => $existing !== $role,
        ));

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

        return $this;
    }

    public function getDataValue(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function setDataValue(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Profil bilgileri (ad, soyad, avatar, biyografi) $data JSON kolonunda
     * yaşar (bkz. sınıf üstü doküman). Bu getter/setter'lar Node'un
     * "featured_image_asset_id" desenindeki gibi doğrudan JSON anahtarına
     * erişim sağlar; Asset ile foreign key İLİŞKİSİ kurulmaz (Manifesto
     * Law 3 hibrit model felsefesi).
     */
    public function getFirstName(): string
    {
        return (string) $this->getDataValue('first_name', '');
    }

    public function setFirstName(string $firstName): static
    {
        return $this->setDataValue('first_name', $firstName);
    }

    public function getLastName(): string
    {
        return (string) $this->getDataValue('last_name', '');
    }

    public function setLastName(string $lastName): static
    {
        return $this->setDataValue('last_name', $lastName);
    }

    public function getFullName(): string
    {
        $fullName = trim($this->getFirstName().' '.$this->getLastName());

        return $fullName !== '' ? $fullName : $this->email;
    }

    public function getBio(): string
    {
        return (string) $this->getDataValue('bio', '');
    }

    public function setBio(string $bio): static
    {
        return $this->setDataValue('bio', $bio);
    }

    /**
     * Avatar için kullanılan App\Entity\Asset kimliği, seçilmemişse null.
     * Çözümleme (Asset::getStorageKey() -> URL) çağıran tarafın
     * AssetRepository üzerinden yapması beklenir (bkz. PostAdminController::resolveAssetUrl
     * ile aynı desen).
     */
    public function getAvatarAssetId(): ?int
    {
        $value = $this->getDataValue('avatar_asset_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function setAvatarAssetId(?int $avatarAssetId): static
    {
        return $this->setDataValue('avatar_asset_id', $avatarAssetId);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Hassas geçici veriyi (ör. düz metin şifre) session'dan temizler.
     * Symfony 7'de UserInterface'in bir parçası değildir ama password
     * hasher akışında güvenlik alışkanlığı olarak boş bırakılır.
     */
    public function eraseCredentials(): void
    {
    }
}
