<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Hybrid user: filtered columns plus JSON $data. Auth is capabilities, not Symfony ROLE_*.
 * getRoles() returns ROLE_USER for the firewall; CPalius roles live in YAML via RoleConfigManager.
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
     * Optional login alias. getUserIdentifier() stays email for session compatibility.
     */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * CPalius role ids (not FKs). Unknown ids are ignored by CPaliusVoter.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * Profile and module fields that are not filtered as columns.
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
     * Public profile URL segment: username when URL-safe, otherwise numeric id.
     */
    public function getProfileSlug(): string
    {
        $username = trim((string) ($this->username ?? ''));
        if ($username !== '' && !ctype_digit($username) && preg_match('/^[a-zA-Z0-9_.-]+$/', $username) === 1) {
            return $username;
        }

        return (string) ($this->id ?? '');
    }

    /**
     * Symfony session / remember-me identifier (email).
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
     * Firewall base role only. Real authorization uses CPalius capabilities.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * CPalius role ids for RoleConfigManager / CPaliusVoter. Not Symfony ROLE_*.
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
     * Name fields live in $data JSON (hybrid model, no Asset FK).
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
     * Avatar Asset id in JSON, or null. Callers resolve the URL via AssetRepository.
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

    public function isEmailVerified(): bool
    {
        return $this->getDataValue('email_verified_at') !== null;
    }

    public function markEmailVerified(): void
    {
        $this->setDataValue('email_verified_at', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $this->setDataValue('email_verification_token', null);
    }

    public function getEmailVerificationToken(): ?string
    {
        $token = $this->getDataValue('email_verification_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function setEmailVerificationToken(?string $token): static
    {
        return $this->setDataValue('email_verification_token', $token);
    }

    public function markRegistrationApproved(): void
    {
        $this->setDataValue('registration_pending_approval', false);
        $this->setDataValue('approved_at', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
    }

    public function getLocation(): string
    {
        return (string) $this->getDataValue('location', '');
    }

    public function setLocation(string $location): static
    {
        return $this->setDataValue('location', $location);
    }

    public function getSignature(): string
    {
        return (string) $this->getDataValue('signature', '');
    }

    public function setSignature(string $signature): static
    {
        return $this->setDataValue('signature', mb_substr($signature, 0, 500));
    }

    public function getCustomTitle(): string
    {
        return (string) $this->getDataValue('custom_title', '');
    }

    public function setCustomTitle(string $title): static
    {
        return $this->setDataValue('custom_title', mb_substr($title, 0, 120));
    }

    public function getCustomTitleColor(): string
    {
        $color = (string) $this->getDataValue('custom_title_color', '');

        return \preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '';
    }

    public function setCustomTitleColor(string $color): static
    {
        $color = \trim($color);
        if (\preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
            $color = '';
        }

        return $this->setDataValue('custom_title_color', $color);
    }

    /**
     * Visual style for the forum custom title: plain, bold, or badge.
     */
    public function getCustomTitleStyle(): string
    {
        $style = (string) $this->getDataValue('custom_title_style', 'plain');

        return \in_array($style, ['plain', 'bold', 'badge'], true) ? $style : 'plain';
    }

    public function setCustomTitleStyle(string $style): static
    {
        if (!\in_array($style, ['plain', 'bold', 'badge'], true)) {
            $style = 'plain';
        }

        return $this->setDataValue('custom_title_style', $style);
    }

    public function getCustomTitleIcon(): string
    {
        return (string) $this->getDataValue('custom_title_icon', '');
    }

    public function setCustomTitleIcon(string $icon): static
    {
        $icon = \trim($icon);
        if ($icon !== '' && \preg_match('/^bi-[a-z0-9-]+$/i', $icon) !== 1) {
            $icon = '';
        }

        return $this->setDataValue('custom_title_icon', $icon);
    }

    /**
     * Clears sensitive temporaries. Empty: Symfony 7 UserInterface no longer requires it.
     */
    public function eraseCredentials(): void
    {
    }
}
