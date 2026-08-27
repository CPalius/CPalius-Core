<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumPostRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Forum mesajı — Cotonti cot_forum_posts tablosunun Doctrine karşılığı.
 */
#[ORM\Entity(repositoryClass: ForumPostRepository::class)]
#[ORM\Table(name: 'forum_posts')]
#[ORM\Index(columns: ['created_at'], name: 'idx_forum_post_created')]
#[ORM\Index(columns: ['topic_id', 'id'], name: 'idx_forum_post_topic')]
class ForumPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumTopic $topic;

    #[ORM\ManyToOne(targetEntity: ForumSection::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumSection $section;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'poster_name', type: 'string', length: 100)]
    private string $posterName;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'updated_by_name', type: 'string', length: 100, nullable: true)]
    private ?string $updatedByName = null;

    #[ORM\Column(name: 'poster_ip', type: 'string', length: 64, nullable: true)]
    private ?string $posterIp = null;

    public function __construct(ForumTopic $topic, ForumSection $section, string $posterName, string $body)
    {
        $this->topic = $topic;
        $this->section = $section;
        $this->posterName = $posterName;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getSection(): ForumSection
    {
        return $this->section;
    }

    public function setSection(ForumSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getPosterName(): string
    {
        return $this->posterName;
    }

    public function setPosterName(string $posterName): static
    {
        $this->posterName = $posterName;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getUpdatedByName(): ?string
    {
        return $this->updatedByName;
    }

    public function setUpdatedByName(?string $updatedByName): static
    {
        $this->updatedByName = $updatedByName;

        return $this;
    }

    public function getPosterIp(): ?string
    {
        return $this->posterIp;
    }

    public function setPosterIp(?string $posterIp): static
    {
        $this->posterIp = $posterIp;

        return $this;
    }
}
