<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumModeratorSubjectType;
use Modules\Forum\Repository\ForumModeratorCacheRepository;

/**
 * Rebuilt index vitrine (cp_forum_moderator_cache).
 * Written only when moderator assignments change; index pages do not JOIN cp_forum_moderators.
 */
#[ORM\Entity(repositoryClass: ForumModeratorCacheRepository::class)]
#[ORM\Table(name: 'cp_forum_moderator_cache')]
#[ORM\UniqueConstraint(name: 'uniq_fmc_section_user', columns: ['section_id', 'user_id', 'subject_type', 'display_name'])]
#[ORM\Index(columns: ['section_id'], name: 'IDX_FORUM_MOD_CACHE_SECTION')]
#[ORM\Index(columns: ['user_id'], name: 'IDX_FORUM_MOD_CACHE_USER')]
class ForumModeratorCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumSection $section;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'display_name', type: 'string', length: 100)]
    private string $displayName;

    #[ORM\Column(name: 'subject_type', type: 'string', length: 8, enumType: ForumModeratorSubjectType::class)]
    private ForumModeratorSubjectType $subjectType;

    #[ORM\Column(name: 'display_on_index', type: 'boolean', options: ['default' => true])]
    private bool $displayOnIndex = true;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(
        ForumSection $section,
        ForumModeratorSubjectType $subjectType,
        string $displayName,
        ?User $user = null,
    ) {
        $this->section = $section;
        $this->subjectType = $subjectType;
        $this->displayName = $displayName;
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ForumSection
    {
        return $this->section;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getSubjectType(): ForumModeratorSubjectType
    {
        return $this->subjectType;
    }

    public function displaysOnIndex(): bool
    {
        return $this->displayOnIndex;
    }

    public function setDisplayOnIndex(bool $displayOnIndex): static
    {
        $this->displayOnIndex = $displayOnIndex;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
