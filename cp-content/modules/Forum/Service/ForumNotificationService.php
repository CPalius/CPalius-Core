<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Notification\Entity\Notification;
use App\Core\Notification\NotificationDispatcher;
use App\Core\Notification\NotificationSubject;
use App\Core\Notification\Repository\NotificationRepository;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Notification\ForumInboxItem;
use Modules\Forum\Notification\ForumNotificationType;
use Modules\Forum\Repository\ForumPostRepository;

/**
 * Forum notifications via core NotificationDispatcher (module → core).
 * Preference keys stay forum_notif_* in User::$data; inbox rows live in cp_notifications.
 */
final class ForumNotificationService
{
    public const EVENT_PREFIX = 'forum.';

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumQuoteParser $quoteParser,
        private readonly ForumMentionParser $mentionParser,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly ForumWatchService $watchService,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.notifications_enabled', true);
    }

    public static function eventKey(string $type): string
    {
        return self::EVENT_PREFIX.$type;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notify(
        User $receiver,
        string $type,
        string $contentType,
        ?int $contentId,
        array $data,
        ?User $sender = null,
        bool $flush = true,
    ): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $notification = $this->dispatcher->dispatch(
            self::eventKey($type),
            $receiver,
            $data + ['content_type' => $contentType],
            $sender,
            new NotificationSubject($contentType, $contentId),
        );

        // Core dispatcher flushes on write; $flush kept for call-site compatibility.
        unset($flush);

        return $notification instanceof Notification;
    }

    /**
     * No-op: NotificationDispatcher persists immediately. Kept for subscriber API.
     */
    public function flush(): void
    {
    }

    public function notifyTopicReply(ForumPost $post, User $author, bool $flush = true): void
    {
        $topic = $post->getTopic();
        $topicAuthor = $topic->getFirstPoster();
        if ($topicAuthor === null || $topicAuthor->getId() === $author->getId()) {
            return;
        }

        $this->notify(
            $topicAuthor,
            ForumNotificationType::REPLY,
            ForumNotificationType::CONTENT_POST,
            $post->getId(),
            $this->postPayload($post, $author),
            $author,
            $flush,
        );
    }

    public function notifyThreadParticipants(ForumPost $post, User $author, bool $flush = true): void
    {
        $topic = $post->getTopic();
        $topicAuthor = $topic->getFirstPoster();
        $skip = [$author->getId() => true];

        if ($topicAuthor !== null) {
            $skip[$topicAuthor->getId()] = true;
        }

        foreach ($this->postRepository->findDistinctAuthorsByTopic($topic) as $participant) {
            $uid = $participant->getId();
            if ($uid === null || isset($skip[$uid])) {
                continue;
            }
            $skip[$uid] = true;

            $this->notify(
                $participant,
                ForumNotificationType::THREAD_REPLY,
                ForumNotificationType::CONTENT_POST,
                $post->getId(),
                $this->postPayload($post, $author),
                $author,
                $flush,
            );
        }

        $this->notifyWatchers($post, $author, $skip, $flush);
    }

    /**
     * @param array<int, true> $skip
     */
    public function notifyWatchers(ForumPost $post, User $author, array $skip, bool $flush = true): void
    {
        foreach ($this->watchService->watchers($post->getTopic()) as $watcher) {
            $uid = $watcher->getId();
            if ($uid === null || isset($skip[$uid])) {
                continue;
            }
            $skip[$uid] = true;
            $this->notify(
                $watcher,
                ForumNotificationType::WATCH,
                ForumNotificationType::CONTENT_POST,
                $post->getId(),
                $this->postPayload($post, $author),
                $author,
                $flush,
            );
        }
    }

    public function notifyQuotes(ForumPost $post, User $author, bool $flush = true): void
    {
        $quotedPostIds = $this->quoteParser->extractQuotedPostIds($post->getBody());
        if ($quotedPostIds === []) {
            return;
        }

        $quotedPosts = $this->postRepository->findBy(['id' => $quotedPostIds]);
        $notified = [];

        foreach ($quotedPosts as $quotedPost) {
            $quotedAuthor = $quotedPost->getAuthor();
            if ($quotedAuthor === null || $quotedAuthor->getId() === $author->getId()) {
                continue;
            }

            $uid = $quotedAuthor->getId();
            if ($uid === null || isset($notified[$uid])) {
                continue;
            }
            $notified[$uid] = true;

            $this->notify(
                $quotedAuthor,
                ForumNotificationType::QUOTE,
                ForumNotificationType::CONTENT_POST,
                $post->getId(),
                $this->postPayload($post, $author) + [
                    'quoted_post_id' => $quotedPost->getId(),
                ],
                $author,
                $flush,
            );
        }
    }

    public function notifyMentions(ForumPost $post, User $author, bool $flush = true): void
    {
        $mentioned = $this->mentionParser->extractMentionedUsers($post->getBody());
        $notified = [];

        foreach ($mentioned as $user) {
            $uid = $user->getId();
            if ($uid === null || $uid === $author->getId() || isset($notified[$uid])) {
                continue;
            }
            $notified[$uid] = true;

            $this->notify(
                $user,
                ForumNotificationType::MENTION,
                ForumNotificationType::CONTENT_POST,
                $post->getId(),
                $this->postPayload($post, $author),
                $author,
                $flush,
            );
        }
    }

    public function notifyLike(ForumPost $post, User $liker, User $postAuthor, bool $flush = true): void
    {
        $this->notify(
            $postAuthor,
            ForumNotificationType::REACTION,
            ForumNotificationType::CONTENT_POST,
            $post->getId(),
            $this->postPayload($post, $liker),
            $liker,
            $flush,
        );
    }

    public function notifyDislike(ForumPost $post, User $disliker, User $postAuthor, bool $flush = true): void
    {
        $this->notify(
            $postAuthor,
            ForumNotificationType::DISLIKE,
            ForumNotificationType::CONTENT_POST,
            $post->getId(),
            $this->postPayload($post, $disliker),
            $disliker,
            $flush,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function notifyReputation(User $receiver, User $giver, int $value, array $extra = [], bool $flush = true): void
    {
        $this->notify(
            $receiver,
            ForumNotificationType::REPUTATION,
            ForumNotificationType::CONTENT_USER,
            $receiver->getId(),
            array_merge([
                'from_user_id' => $giver->getId(),
                'from_username' => $giver->getFullName(),
                'value' => $value,
            ], $extra),
            $giver,
            $flush,
        );
    }

    public function countUnread(User $user): int
    {
        return $this->notifications->countUnreadByEventPrefix($user, self::EVENT_PREFIX);
    }

    public function markAllRead(User $user): void
    {
        $this->notifications->markAllReadByEventPrefix($user, self::EVENT_PREFIX);
    }

    public function markRead(Notification $notification): void
    {
        if (!$notification->isRead()) {
            $notification->markRead();
            $this->entityManager->flush();
        }
    }

    public function findOwned(User $user, int $id): ?Notification
    {
        $notification = $this->notifications->find($id);
        if (!$notification instanceof Notification || $notification->getUser()->getId() !== $user->getId()) {
            return null;
        }
        if (!str_starts_with($notification->getEventKey(), self::EVENT_PREFIX)) {
            return null;
        }

        return $notification;
    }

    public function createInboxQueryBuilder(User $user, ?string $shortType = null): QueryBuilder
    {
        $eventKey = $shortType !== null && $shortType !== '' ? self::eventKey($shortType) : null;

        return $this->notifications->createForUserByEventPrefixQueryBuilder($user, self::EVENT_PREFIX, $eventKey);
    }

    /**
     * @return list<ForumInboxItem>
     */
    public function recentForUser(User $user, int $limit = 6): array
    {
        return array_map(
            static fn (Notification $n): ForumInboxItem => new ForumInboxItem($n),
            $this->notifications->findForUserByEventPrefix($user, self::EVENT_PREFIX, $limit),
        );
    }

    public function wrap(Notification $notification): ForumInboxItem
    {
        return new ForumInboxItem($notification);
    }

    /** @return array<string, mixed> */
    private function postPayload(ForumPost $post, User $from): array
    {
        $topic = $post->getTopic();

        return [
            'url' => null,
            'from_user_id' => $from->getId(),
            'from_username' => $from->getFullName(),
            'topic_id' => $topic->getId(),
            'topic_title' => $topic->getTitle(),
            'topic_slug' => $topic->getSlug(),
            'post_id' => $post->getId(),
        ];
    }
}
