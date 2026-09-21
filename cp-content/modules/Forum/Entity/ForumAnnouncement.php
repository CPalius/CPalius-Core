<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumAnnouncementRepository;

/**
 * Board announcement (cp_forum_announcements).
 * section_id NULL = global (every section).
 */
#[ORM\Entity(repositoryClass: ForumAnnouncementRepository::class)]
#[ORM\Table(name: 'cp_forum_announcements')]
#[ORM\Index(columns: ['section_id'], name: 'idx_forum_announcement_section')]
#[ORM\Index(columns: ['is_active', 'starts_at', 'ends_at'], name: 'idx_forum_announcement_window')]
class ForumAnnouncement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ForumSection $section = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $body, ?ForumSection $section = null)
    {
        $this->body = $body;
        $this->section = $section;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ?ForumSection
    {
        return $this->section;
    }

    public function setSection(?ForumSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function isGlobal(): bool
    {
        return $this->section === null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
