<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumModeratorSubjectType;
use Modules\Forum\ForumPermission;
use Modules\Forum\Repository\ForumModeratorRepository;

/**
 * Local moderator assignment (cp_forum_moderators).
 * Empty grant_keys means the standard_local_mod pack at resolve time.
 * subject_key is '' on user rows so the unique key never sees NULL.
 */
#[ORM\Entity(repositoryClass: ForumModeratorRepository::class)]
#[ORM\Table(name: 'cp_forum_moderators')]
#[ORM\UniqueConstraint(name: 'uniq_forum_mod_slot', columns: ['section_id', 'subject_type', 'subject_id', 'subject_key'])]
#[ORM\Index(columns: ['section_id'], name: 'IDX_FORUM_MOD_SECTION')]
#[ORM\Index(columns: ['created_by_id'], name: 'IDX_FORUM_MOD_CREATED_BY')]
class ForumModerator
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumSection $section;

    #[ORM\Column(name: 'subject_type', type: 'string', length: 8, enumType: ForumModeratorSubjectType::class)]
    private ForumModeratorSubjectType $subjectType;

    #[ORM\Column(name: 'subject_id', type: 'integer')]
    private int $subjectId;

    #[ORM\Column(name: 'subject_key', type: 'string', length: 32, options: ['default' => ''])]
    private string $subjectKey = '';

    #[ORM\Column(name: 'inherit_children', type: 'boolean', options: ['default' => true])]
    private bool $inheritChildren = true;

    /** @var list<string> */
    #[ORM\Column(name: 'grant_keys', type: 'json')]
    private array $grantKeys = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ForumSection $section,
        ForumModeratorSubjectType $subjectType,
        int $subjectId,
        string $subjectKey = '',
    ) {
        $this->section = $section;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->subjectKey = $subjectType === ForumModeratorSubjectType::User ? '' : $subjectKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ForumSection
    {
        return $this->section;
    }

    public function getSubjectType(): ForumModeratorSubjectType
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getSubjectKey(): string
    {
        return $this->subjectKey;
    }

    public function inheritsChildren(): bool
    {
        return $this->inheritChildren;
    }

    public function setInheritChildren(bool $inheritChildren): static
    {
        $this->inheritChildren = $inheritChildren;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getGrantKeys(): array
    {
        return $this->grantKeys;
    }

    /**
     * Empty list = standard_local_mod pack (resolved by the future ACL service).
     *
     * @return list<string>
     */
    public function resolvedGrantKeys(): array
    {
        if ($this->grantKeys === []) {
            return array_map(
                static fn (ForumPermission $p): string => $p->value,
                ForumPermission::standardLocalModGrants(),
            );
        }

        return $this->grantKeys;
    }

    /**
     * @param list<string> $grantKeys
     */
    public function setGrantKeys(array $grantKeys): static
    {
        $this->grantKeys = array_values($grantKeys);

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
