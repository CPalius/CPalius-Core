<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumPostRepository;

/**
 * CPalius Forum Engine post (cp_forum_post).
 *
 * thread_id, user_id, message (CKEditor 5 HTML), attach_count,
 * edit_count, last_edit_date, ip_address.
 */
#[ORM\Entity(repositoryClass: ForumPostRepository::class)]
#[ORM\Table(name: 'cp_forum_posts')]
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

    #[ORM\Column(name: 'attach_count', type: 'integer')]
    private int $attachCount = 0;

    #[ORM\Column(name: 'edit_count', type: 'integer')]
    private int $editCount = 0;

    #[ORM\Column(name: 'last_edit_date', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastEditDate = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'updated_by_name', type: 'string', length: 100, nullable: true)]
    private ?string $updatedByName = null;

    #[ORM\Column(name: 'poster_ip', type: 'string', length: 64, nullable: true)]
    private ?string $posterIp = null;

    #[ORM\Column(name: 'discussion_state', type: 'string', length: 16, enumType: ForumDiscussionState::class)]
    private ForumDiscussionState $discussionState = ForumDiscussionState::Visible;

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

    public function setTopic(ForumTopic $topic): static
    {
        $this->topic = $topic;

        return $this;
    }

    public function getThread(): ForumTopic
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

    /** CPalius Forum Engine cp_forum_post.message */
    public function getMessage(): string
    {
        return $this->body;
    }

    public function setMessage(string $message): static
    {
        $this->body = $message;

        return $this;
    }

    public function getAttachCount(): int
    {
        return $this->attachCount;
    }

    public function setAttachCount(int $attachCount): static
    {
        $this->attachCount = $attachCount;

        return $this;
    }

    public function getEditCount(): int
    {
        return $this->editCount;
    }

    public function setEditCount(int $editCount): static
    {
        $this->editCount = $editCount;

        return $this;
    }

    public function recordEdit(string $editorName): static
    {
        ++$this->editCount;
        $now = new \DateTimeImmutable();
        $this->lastEditDate = $now;
        $this->updatedAt = $now;
        $this->updatedByName = $editorName;

        return $this;
    }

    public function getLastEditDate(): ?\DateTimeImmutable
    {
        return $this->lastEditDate ?? $this->updatedAt;
    }

    public function setLastEditDate(?\DateTimeImmutable $lastEditDate): static
    {
        $this->lastEditDate = $lastEditDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Puts back the original posting time when a post is imported from another
     * forum or restored from a backup.
     *
     * Deliberately not called setCreatedAt: everywhere else this is the moment
     * the row was made, and a general setter would invite moving it. But an
     * imported forum whose posts are all dated the day of the migration has
     * lost the one thing a forum archive is for — the order and age of the
     * conversation — so the import needs this door, clearly labelled.
     */
    public function restoreCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
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

    /** CPalius Forum Engine cp_forum_post.ip_id / ip_address */
    public function getIpAddress(): ?string
    {
        return $this->posterIp;
    }

    public function getDiscussionState(): ForumDiscussionState
    {
        return $this->discussionState;
    }

    public function setDiscussionState(ForumDiscussionState $discussionState): static
    {
        $this->discussionState = $discussionState;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->discussionState === ForumDiscussionState::Visible;
    }

    public function isModerated(): bool
    {
        return $this->discussionState === ForumDiscussionState::Moderated;
    }
}
