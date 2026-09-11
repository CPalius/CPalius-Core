<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a forum post is created (thread opener or reply).
 * Starts the notification and hook chain.
 */
final class ForumPostCreatedEvent extends Event
{
    public const NAME = 'forum.post.created';

    public function __construct(
        private readonly ForumPost $post,
        private readonly ForumTopic $topic,
        private readonly User $author,
        private readonly bool $isFirstPost,
    ) {
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function isFirstPost(): bool
    {
        return $this->isFirstPost;
    }
}
