<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumTopicViewBufferRepository;

/**
 * Insert-only topic view heap (cp_forum_topic_view_buffer).
 * A periodic flush adds COUNT(*) per topic_id onto topics.view_count, then deletes these rows.
 * No unique on topic_id: many rows per topic is the point.
 */
#[ORM\Entity(repositoryClass: ForumTopicViewBufferRepository::class)]
#[ORM\Table(name: 'cp_forum_topic_view_buffer')]
#[ORM\Index(columns: ['topic_id'], name: 'idx_forum_view_buf_topic')]
class ForumTopicViewBuffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumTopic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumTopic $topic;

    #[ORM\Column(name: 'seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $seenAt;

    public function __construct(ForumTopic $topic)
    {
        $this->topic = $topic;
        $this->seenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getSeenAt(): \DateTimeImmutable
    {
        return $this->seenAt;
    }
}
