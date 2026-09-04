<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use Modules\Forum\Entity\ForumNotification;
use Modules\Forum\Entity\ForumPost;
use App\Entity\User;
use Modules\Forum\Repository\ForumNotificationRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Forum notifications. Preference keys live in User::$data JSON.
 */
final class ForumNotificationService
{
    /** @var array<string, string|null> type => preference key (null = always) */
    private const PREFERENCE_BY_TYPE = [
        ForumNotification::TYPE_REPLY => 'forum_notif_reply',
        ForumNotification::TYPE_THREAD_REPLY => 'forum_notif_thread',
        ForumNotification::TYPE_QUOTE => 'forum_notif_quote',
        ForumNotification::TYPE_REACTION => 'forum_notif_reaction',
        ForumNotification::TYPE_DISLIKE => 'forum_notif_dislike',
        ForumNotification::TYPE_MENTION => 'forum_notif_mention',
        ForumNotification::TYPE_REPUTATION => null,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumNotificationRepository $notificationRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumQuoteParser $quoteParser,
        private readonly ForumMentionParser $mentionParser,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.notifications_enabled', true);
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

        if ($sender !== null && $sender->getId() === $receiver->getId()) {
            return false;
        }

        if ($receiver->getStatus() === User::STATUS_BANNED) {
            return false;
        }

        if (!$this->userReceives($receiver, $type)) {
            return false;
        }

        $notification = new ForumNotification(
            $receiver,
            $type,
            $contentType,
            $contentId,
            $data,
            $sender,
            $sender?->getFullName(),
        );

        $this->entityManager->persist($notification);

        if ($flush) {
            $this->entityManager->flush();
        }

        return true;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
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
            ForumNotification::TYPE_REPLY,
            ForumNotification::CONTENT_POST,
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
                ForumNotification::TYPE_THREAD_REPLY,
                ForumNotification::CONTENT_POST,
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
                ForumNotification::TYPE_QUOTE,
                ForumNotification::CONTENT_POST,
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
                ForumNotification::TYPE_MENTION,
                ForumNotification::CONTENT_POST,
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
            ForumNotification::TYPE_REACTION,
            ForumNotification::CONTENT_POST,
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
            ForumNotification::TYPE_DISLIKE,
            ForumNotification::CONTENT_POST,
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
            ForumNotification::TYPE_REPUTATION,
            ForumNotification::CONTENT_USER,
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
        return $this->notificationRepository->countUnreadForUser($user);
    }

    public function markAllRead(User $user): void
    {
        $this->notificationRepository->markAllReadForUser($user);
    }

    public function markRead(ForumNotification $notification): void
    {
        if (!$notification->isRead()) {
            $notification->markRead();
            $this->entityManager->flush();
        }
    }

    private function userReceives(User $user, string $type): bool
    {
        $prefKey = self::PREFERENCE_BY_TYPE[$type] ?? null;
        if ($prefKey === null) {
            return true;
        }

        $value = $user->getDataValue($prefKey, true);

        return $value !== false && $value !== 0 && $value !== '0';
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
