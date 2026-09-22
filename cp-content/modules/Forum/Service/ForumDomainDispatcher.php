<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Hook\HookContext;
use App\Core\Hook\HookManager;
use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumUserReputation;
use Modules\Forum\Event\ForumPostCreatedEvent;
use Modules\Forum\Event\ForumPostDislikedEvent;
use Modules\Forum\Event\ForumPostLikedEvent;
use Modules\Forum\Event\ForumReputationGivenEvent;
use Modules\Forum\Event\ForumTopicCreatedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Single exit for forum domain events: Symfony EventDispatcher plus HookManager.
 * Services call this so both tracks always fire together.
 */
final class ForumDomainDispatcher
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly HookManager $hookManager,
    ) {
    }

    public function dispatchPostCreated(ForumPost $post, ForumTopic $topic, User $author, bool $isFirstPost): void
    {
        $this->eventDispatcher->dispatch(
            new ForumPostCreatedEvent($post, $topic, $author, $isFirstPost),
            ForumPostCreatedEvent::NAME,
        );

        $hookPoint = $isFirstPost ? 'forum.topic.created' : 'forum.reply';
        $this->hookManager->trigger($hookPoint, new HookContext([
            'post' => $post,
            'topic' => $topic,
            'author' => $author,
            'topic_author' => $topic->getFirstPoster(),
            'section' => $topic->getSection(),
            'is_first_post' => $isFirstPost,
        ]));
    }

    public function dispatchTopicCreated(ForumTopic $topic, ForumPost $openingPost, User $author, Request $request, bool $autoTranslate): void
    {
        if (!$autoTranslate) {
            return;
        }

        $this->eventDispatcher->dispatch(
            new ForumTopicCreatedEvent($topic, $openingPost, $author, $request, true),
            ForumTopicCreatedEvent::NAME,
        );
    }

    public function dispatchTopicTranslate(ForumTopic $topic, ForumPost $openingPost, User $author, Request $request): void
    {
        $this->eventDispatcher->dispatch(
            new ForumTopicCreatedEvent($topic, $openingPost, $author, $request, true),
            'forum.topic.translate',
        );
    }

    public function dispatchQuote(ForumPost $post, ForumTopic $topic, User $author, ForumPost $quotedPost, User $quotedAuthor): void
    {
        $this->hookManager->trigger('forum.quote', new HookContext([
            'post' => $post,
            'topic' => $topic,
            'author' => $author,
            'quoted_post' => $quotedPost,
            'quoted_author' => $quotedAuthor,
        ]));
    }

    public function dispatchPostLiked(ForumPost $post, User $liker, User $postAuthor): void
    {
        $this->eventDispatcher->dispatch(
            new ForumPostLikedEvent($post, $liker, $postAuthor),
            ForumPostLikedEvent::NAME,
        );

        $this->hookManager->trigger('forum.like', new HookContext([
            'post' => $post,
            'liker' => $liker,
            'post_author' => $postAuthor,
            'topic' => $post->getTopic(),
            'liked' => true,
        ]));
    }

    public function dispatchPostDisliked(ForumPost $post, User $disliker, User $postAuthor): void
    {
        $this->eventDispatcher->dispatch(
            new ForumPostDislikedEvent($post, $disliker, $postAuthor),
            ForumPostDislikedEvent::NAME,
        );

        $this->hookManager->trigger('forum.dislike', new HookContext([
            'post' => $post,
            'disliker' => $disliker,
            'post_author' => $postAuthor,
            'topic' => $post->getTopic(),
            'disliked' => true,
        ]));
    }

    public function dispatchReputationGiven(ForumUserReputation $reputation): void
    {
        $from = $reputation->getFromUser();
        $to = $reputation->getToUser();

        $this->eventDispatcher->dispatch(
            new ForumReputationGivenEvent($reputation, $from, $to),
            ForumReputationGivenEvent::NAME,
        );

        $this->hookManager->trigger('forum.reputation', new HookContext([
            'reputation' => $reputation,
            'from_user' => $from,
            'to_user' => $to,
            'value' => $reputation->getValue(),
            'reason' => $reputation->getReason(),
            'topic' => $reputation->getTopic(),
            'post' => $reputation->getPost(),
        ]));
    }
}
