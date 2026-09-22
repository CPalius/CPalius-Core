<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired after a topic is created when the author asked for AI auto-translate.
 * Ai listens by NAME; Forum never imports the Ai module.
 */
final class ForumTopicCreatedEvent extends Event
{
    public const NAME = 'forum.topic.created';

    public function __construct(
        private readonly ForumTopic $topic,
        private readonly ForumPost $openingPost,
        private readonly User $author,
        private readonly Request $request,
        private readonly bool $autoTranslate,
    ) {
    }

    public function getTopic(): ForumTopic
    {
        return $this->topic;
    }

    public function getOpeningPost(): ForumPost
    {
        return $this->openingPost;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function shouldTranslate(): bool
    {
        return $this->autoTranslate;
    }
}
