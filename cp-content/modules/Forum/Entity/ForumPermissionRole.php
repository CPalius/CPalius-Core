<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumPermissionRoleScope;
use Modules\Forum\Repository\ForumPermissionRoleRepository;

/**
 * Named ACL pack (cp_forum_permission_roles): Standard Member, Read Only, Local Mod.
 */
#[ORM\Entity(repositoryClass: ForumPermissionRoleRepository::class)]
#[ORM\Table(name: 'cp_forum_permission_roles')]
#[ORM\UniqueConstraint(name: 'uniq_forum_perm_role_code', columns: ['code'])]
class ForumPermissionRole
{
    public const CODE_READ_ONLY = 'read_only';
    public const CODE_STANDARD_MEMBER = 'standard_member';
    public const CODE_STANDARD_LOCAL_MOD = 'standard_local_mod';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $code;

    #[ORM\Column(type: 'string', length: 64)]
    private string $label;

    #[ORM\Column(type: 'string', length: 16, enumType: ForumPermissionRoleScope::class)]
    private ForumPermissionRoleScope $scope;

    /** @var Collection<int, ForumPermissionRoleGrant> */
    #[ORM\OneToMany(targetEntity: ForumPermissionRoleGrant::class, mappedBy: 'role', cascade: ['persist'], orphanRemoval: true)]
    private Collection $grants;

    public function __construct(string $code, string $label, ForumPermissionRoleScope $scope)
    {
        $this->code = $code;
        $this->label = $label;
        $this->scope = $scope;
        $this->grants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getScope(): ForumPermissionRoleScope
    {
        return $this->scope;
    }

    /**
     * @return Collection<int, ForumPermissionRoleGrant>
     */
    public function getGrants(): Collection
    {
        return $this->grants;
    }

    public function addGrant(ForumPermissionRoleGrant $grant): static
    {
        if (!$this->grants->contains($grant)) {
            $this->grants->add($grant);
        }

        return $this;
    }
}
