<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired after an opening post is edited and the editor asked for its existing
 * translations to follow. Carries the pre-edit text so a listener can work out
 * what actually changed. Ai listens by NAME; Forum never imports the Ai module.
 */
final class ForumTopicTranslationSyncEvent extends Event
{
    public const NAME = 'forum.topic.translation.sync';

    public function __construct(
        private readonly ForumTopic $topic,
        private readonly ForumPost $openingPost,
        private readonly User $author,
        private readonly Request $request,
        private readonly string $previousTitle,
        private readonly string $previousDescription,
        private readonly string $previousBody,
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

    public function getPreviousTitle(): string
    {
        return $this->previousTitle;
    }

    public function getPreviousDescription(): string
    {
        return $this->previousDescription;
    }

    public function getPreviousBody(): string
    {
        return $this->previousBody;
    }
}
