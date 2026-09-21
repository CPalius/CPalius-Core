<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumReadMarkerRepository;

#[ORM\Entity(repositoryClass: ForumReadMarkerRepository::class)]
#[ORM\Table(name: 'cp_forum_read_markers')]
#[ORM\UniqueConstraint(name: 'uniq_forum_read_user_section', columns: ['user_id', 'section_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_forum_read_user')]
class ForumReadMarker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ForumSection $section = null;

    #[ORM\Column(name: 'marked_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $markedAt;

    public function __construct(User $user, ?ForumSection $section = null)
    {
        $this->user = $user;
        $this->section = $section;
        $this->markedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSection(): ?ForumSection
    {
        return $this->section;
    }

    public function getMarkedAt(): \DateTimeImmutable
    {
        return $this->markedAt;
    }

    public function touch(): void
    {
        $this->markedAt = new \DateTimeImmutable();
    }
}
