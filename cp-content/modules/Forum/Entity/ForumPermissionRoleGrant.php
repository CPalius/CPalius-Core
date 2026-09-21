<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumAclEffect;
use Modules\Forum\Repository\ForumPermissionRoleGrantRepository;

/**
 * One permission inside an ACL pack (cp_forum_permission_role_grants).
 */
#[ORM\Entity(repositoryClass: ForumPermissionRoleGrantRepository::class)]
#[ORM\Table(name: 'cp_forum_permission_role_grants')]
#[ORM\UniqueConstraint(name: 'uniq_forum_perm_role_grant', columns: ['role_id', 'permission_key'])]
#[ORM\Index(columns: ['role_id'], name: 'IDX_FORUM_PERM_ROLE_GRANT_ROLE')]
class ForumPermissionRoleGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPermissionRole::class, inversedBy: 'grants')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPermissionRole $role;

    #[ORM\Column(name: 'permission_key', type: 'string', length: 64)]
    private string $permissionKey;

    #[ORM\Column(name: 'effect', type: 'string', length: 8, enumType: ForumAclEffect::class)]
    private ForumAclEffect $effect = ForumAclEffect::Allow;

    public function __construct(ForumPermissionRole $role, string $permissionKey, ForumAclEffect $effect = ForumAclEffect::Allow)
    {
        if ($effect === ForumAclEffect::Inherit) {
            throw new \InvalidArgumentException('Pack grants are allow or deny; inherit is the absence of a row.');
        }

        $this->role = $role;
        $this->permissionKey = $permissionKey;
        $this->effect = $effect;
        $role->addGrant($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): ForumPermissionRole
    {
        return $this->role;
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
            throw new \InvalidArgumentException('Pack grants are allow or deny; inherit is the absence of a row.');
        }

        $this->effect = $effect;

        return $this;
    }
}
