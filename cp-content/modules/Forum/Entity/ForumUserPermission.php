<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumAclEffect;
use Modules\Forum\Repository\ForumUserPermissionRepository;

/**
 * Per-user ACL override (cp_forum_user_permissions).
 * section_id = 0 is the global sentinel — not a real ForumSection row, so no FK.
 * effect is allow|deny only; inherit means "delete the row".
 */
#[ORM\Entity(repositoryClass: ForumUserPermissionRepository::class)]
#[ORM\Table(name: 'cp_forum_user_permissions')]
#[ORM\UniqueConstraint(name: 'uniq_fup_section_user_perm', columns: ['section_id', 'user_id', 'permission_key'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_fup_user')]
class ForumUserPermission
{
    public const GLOBAL_SECTION_ID = 0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'section_id', type: 'integer', options: ['default' => 0])]
    private int $sectionId = self::GLOBAL_SECTION_ID;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'permission_key', type: 'string', length: 64)]
    private string $permissionKey;

    #[ORM\Column(name: 'effect', type: 'string', length: 8, enumType: ForumAclEffect::class)]
    private ForumAclEffect $effect;

    public function __construct(User $user, string $permissionKey, ForumAclEffect $effect, int $sectionId = self::GLOBAL_SECTION_ID)
    {
        if ($effect === ForumAclEffect::Inherit) {
            throw new \InvalidArgumentException('User override rows are allow or deny; inherit is the absence of a row.');
        }

        $this->user = $user;
        $this->permissionKey = $permissionKey;
        $this->effect = $effect;
        $this->sectionId = max(0, $sectionId);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSectionId(): int
    {
        return $this->sectionId;
    }

    public function isGlobal(): bool
    {
        return $this->sectionId === self::GLOBAL_SECTION_ID;
    }

    public function setSectionId(int $sectionId): static
    {
        $this->sectionId = max(0, $sectionId);

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPermissionKey(): string
    {
        return $this->permissionKey;
    }

    public function getEffect(): ForumAclEffect
    {
        return $this->effect;
    }

    public function setEffect(ForumAclEffect $effect): static
    {
        if ($effect === ForumAclEffect::Inherit) {
            throw new \InvalidArgumentException('User override rows are allow or deny; inherit is the absence of a row.');
        }

        $this->effect = $effect;

        return $this;
    }
}
