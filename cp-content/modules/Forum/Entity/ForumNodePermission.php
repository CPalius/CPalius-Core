<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumAclEffect;
use Modules\Forum\ForumPermission;
use Modules\Forum\Repository\ForumNodePermissionRepository;

/**
 * Node × role permission matrix row (cp_forum_node_permissions).
 * Missing row = inherit. Persist should stay sparse (inherit is not stored).
 */
#[ORM\Entity(repositoryClass: ForumNodePermissionRepository::class)]
#[ORM\Table(name: 'cp_forum_node_permissions')]
#[ORM\UniqueConstraint(name: 'UNIQ_FNP_SECTION_ROLE_PERM', columns: ['section_id', 'role_key', 'permission_key'])]
class ForumNodePermission
{
    public const ROLE_GUEST = 'guest';
    public const ROLE_MEMBER = 'member';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_ADMIN = 'admin';

    public const PERM_VIEW = 'view';
    public const PERM_THREAD_CREATE = 'thread_create';
    public const PERM_REPLY = 'reply';
    public const PERM_UPLOAD = 'upload';
    /** @deprecated use ForumPermission::PollCreate — kept so current callers compile */
    public const PERM_POLL = 'poll_create';

    /** @var list<string> */
    public const ROLES = [
        self::ROLE_GUEST,
        self::ROLE_MEMBER,
        self::ROLE_MODERATOR,
        self::ROLE_ADMIN,
    ];

    /**
     * Studio matrix columns still written by ForumPermissionService.
     * Full dictionary lives on ForumPermission.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        self::PERM_VIEW,
        self::PERM_THREAD_CREATE,
        self::PERM_REPLY,
        self::PERM_UPLOAD,
        self::PERM_POLL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumSection $section;

    #[ORM\Column(name: 'role_key', type: 'string', length: 32)]
    private string $roleKey;

    #[ORM\Column(name: 'permission_key', type: 'string', length: 64)]
    private string $permissionKey;

    #[ORM\Column(name: 'effect', type: 'string', length: 8, enumType: ForumAclEffect::class)]
    private ForumAclEffect $effect = ForumAclEffect::Inherit;

    public function __construct(
        ForumSection $section,
        string $roleKey,
        string $permissionKey,
        bool|ForumAclEffect $effect = true,
    ) {
        $this->section = $section;
        $this->roleKey = $roleKey;
        $this->permissionKey = $permissionKey;
        $this->effect = $effect instanceof ForumAclEffect
            ? $effect
            : ($effect ? ForumAclEffect::Allow : ForumAclEffect::Deny);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ForumSection
    {
        return $this->section;
    }

    public function getRoleKey(): string
    {
        return $this->roleKey;
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
        $this->effect = $effect;

        return $this;
    }

    /** Boolean view of the cell: only Allow is true. Inherit/Deny are false. */
    public function isAllowed(): bool
    {
        return $this->effect === ForumAclEffect::Allow;
    }

    public function setAllowed(bool $allowed): static
    {
        $this->effect = $allowed ? ForumAclEffect::Allow : ForumAclEffect::Deny;

        return $this;
    }

    public function getPermission(): ?ForumPermission
    {
        return ForumPermission::tryFrom($this->permissionKey);
    }
}
