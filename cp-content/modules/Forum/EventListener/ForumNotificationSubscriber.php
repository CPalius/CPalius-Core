<?php

declare(strict_types=1);

namespace Modules\Forum\EventListener;

use Modules\Forum\Event\ForumPostCreatedEvent;
use Modules\Forum\Event\ForumPostDislikedEvent;
use Modules\Forum\Event\ForumPostLikedEvent;
use Modules\Forum\Event\ForumReputationGivenEvent;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Service\ForumDomainDispatcher;
use Modules\Forum\Service\ForumNotificationService;
use Modules\Forum\Service\ForumQuoteParser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * CPalius Forum Engine bildirim abonesi.
 */
final class ForumNotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ForumNotificationService $notificationService,
        private readonly ForumQuoteParser $quoteParser,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumDomainDispatcher $domainDispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ForumPostCreatedEvent::NAME => 'onPostCreated',
            ForumPostLikedEvent::NAME => 'onPostLiked',
            ForumPostDislikedEvent::NAME => 'onPostDisliked',
            ForumReputationGivenEvent::NAME => 'onReputationGiven',
        ];
    }

    public function onPostCreated(ForumPostCreatedEvent $event): void
    {
        if ($event->isFirstPost()) {
            return;
        }

        $post = $event->getPost();
        $author = $event->getAuthor();
        $topic = $event->getTopic();

        $this->notificationService->notifyTopicReply($post, $author, flush: false);
        $this->notificationService->notifyThreadParticipants($post, $author, flush: false);
        $this->notificationService->notifyQuotes($post, $author, flush: false);
        $this->notificationService->notifyMentions($post, $author, flush: false);
        $this->notificationService->flush();

        $quotedIds = $this->quoteParser->extractQuotedPostIds($post->getBody());
        if ($quotedIds === []) {
            return;
        }

        foreach ($this->postRepository->findBy(['id' => $quotedIds]) as $quotedPost) {
            $quotedAuthor = $quotedPost->getAuthor();
            if ($quotedAuthor === null || $quotedAuthor->getId() === $author->getId()) {
                continue;
            }
            $this->domainDispatcher->dispatchQuote($post, $topic, $author, $quotedPost, $quotedAuthor);
        }
    }

    public function onPostLiked(ForumPostLikedEvent $event): void
    {
        $this->notificationService->notifyLike(
            $event->getPost(),
            $event->getLiker(),
            $event->getPostAuthor(),
        );
    }

    public function onPostDisliked(ForumPostDislikedEvent $event): void
    {
        $this->notificationService->notifyDislike(
            $event->getPost(),
            $event->getDisliker(),
            $event->getPostAuthor(),
        );
    }

    public function onReputationGiven(ForumReputationGivenEvent $event): void
    {
        $rep = $event->getReputation();
        $extra = [
            'reason' => $rep->getReason(),
            'comment' => $rep->getComment(),
        ];
        if ($rep->getTopic() !== null) {
            $extra['topic_id'] = $rep->getTopic()->getId();
            $extra['topic_title'] = $rep->getTopic()->getTitle();
            $extra['topic_slug'] = $rep->getTopic()->getSlug();
        }
        if ($rep->getPost() !== null) {
            $extra['post_id'] = $rep->getPost()->getId();
        }

        $this->notificationService->notifyReputation(
            $event->getToUser(),
            $event->getFromUser(),
            $rep->getValue(),
            $extra,
        );
    }
}
