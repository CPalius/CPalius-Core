<?php

declare(strict_types=1);

namespace App\Core\Notification;

use App\Core\Notification\Entity\Notification;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a core Notification row into header/pulse JSON the theme can render.
 *
 * Forum event keys stay readable without importing the Forum module: topic
 * routes are generated only when that route exists.
 */
final class NotificationInboxPresenter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{
     *     id: int,
     *     unread: bool,
     *     text: string,
     *     url: string,
     *     icon: string,
     *     event_key: string,
     *     created_at: string,
     *     created_label: string
     * }
     */
    public function toArray(Notification $notification): array
    {
        $id = $notification->getId() ?? 0;

        return [
            'id' => $id,
            'unread' => !$notification->isRead(),
            'text' => $this->text($notification),
            'url' => $this->clickUrl($notification),
            'icon' => $this->icon($notification),
            'event_key' => $notification->getEventKey(),
            'created_at' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'created_label' => $this->relativeTime($notification->getCreatedAt()),
        ];
    }

    public function text(Notification $notification): string
    {
        $key = $notification->getEventKey();
        $data = $notification->getData();

        if (str_starts_with($key, 'forum.')) {
            return $this->forumText(substr($key, 6), $notification, $data);
        }

        $translated = $this->translator->trans('notification.event.'.$key, $this->stringParams($data));
        if ($translated !== 'notification.event.'.$key && $translated !== '') {
            return $translated;
        }

        $name = $notification->getActorName() ?? '';

        return trim($name.' '.$key);
    }

    public function clickUrl(Notification $notification): string
    {
        $id = $notification->getId();
        if ($id !== null && $id > 0) {
            try {
                return $this->urlGenerator->generate('account_notification_go', ['id' => $id]);
            } catch (RoutingException) {
            }
        }

        return $this->destination($notification);
    }

    public function destination(Notification $notification): string
    {
        $data = $notification->getData();
        $fromData = self::sanitizeUrl(isset($data['url']) ? (string) $data['url'] : '');
        if ($fromData !== null) {
            return $fromData;
        }

        $topicId = isset($data['topic_id']) ? (int) $data['topic_id'] : 0;
        if ($topicId > 0) {
            $slug = isset($data['topic_slug']) && is_string($data['topic_slug']) && $data['topic_slug'] !== ''
                ? $data['topic_slug']
                : 'konu';
            $postId = isset($data['post_id']) ? (int) $data['post_id'] : 0;
            try {
                $url = $this->urlGenerator->generate('forum_topic', [
                    'topicId' => $topicId,
                    'slug' => $slug,
                ]);
                if ($postId > 0) {
                    $url .= '#post'.$postId;
                }

                return $url;
            } catch (RoutingException) {
            }
        }

        try {
            return $this->urlGenerator->generate('account_notifications');
        } catch (RoutingException) {
            return '/';
        }
    }

    /**
     * Same-origin only. Used after marking a row read so data.url cannot bounce
     * the browser off-site.
     */
    public function redirectTarget(Notification $notification): string
    {
        $url = $this->destination($notification);
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        try {
            return $this->urlGenerator->generate('account_notifications');
        } catch (RoutingException) {
            return '/';
        }
    }

    public function icon(Notification $notification): string
    {
        return match ($notification->getEventKey()) {
            'forum.reply' => 'reply',
            'forum.thread_reply' => 'chat-dots',
            'forum.quote' => 'chat-quote',
            'forum.mention' => 'at',
            'forum.reaction' => 'heart',
            'forum.dislike' => 'hand-thumbs-down',
            'forum.watch' => 'bell',
            'forum.reputation' => 'award',
            'messages.new' => 'envelope',
            default => 'bell',
        };
    }

    public function relativeTime(\DateTimeInterface $date): string
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();
        if ($diff < 60) {
            return $this->translator->trans('notification.time.just_now');
        }
        if ($diff < 3600) {
            return $this->translator->trans('notification.time.minutes', ['count' => (int) floor($diff / 60)]);
        }
        if ($diff < 86400) {
            return $this->translator->trans('notification.time.hours', ['count' => (int) floor($diff / 3600)]);
        }
        if ($diff < 604800) {
            return $this->translator->trans('notification.time.days', ['count' => (int) floor($diff / 86400)]);
        }

        return $date->format('d.m.Y H:i');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function forumText(string $short, Notification $notification, array $data): string
    {
        $name = $notification->getActorName()
            ?? (isset($data['from_username']) ? (string) $data['from_username'] : '?');
        $topic = isset($data['topic_title']) ? (string) $data['topic_title'] : '';
        $params = ['name' => $name, 'topic' => $topic];

        if ($short === 'reputation') {
            $value = (int) ($data['value'] ?? 1);
            $params['value'] = $value > 0 ? '+1' : '−1';
            $reason = isset($data['reason']) ? (string) $data['reason'] : 'helpful';
            $params['reason'] = $this->translator->trans('forum.rep.reason.'.$reason);
        }

        return $this->translator->trans('forum.notifications.msg.'.$short, $params);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string|int|float>
     */
    private function stringParams(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function sanitizeUrl(string $url): ?string
    {
        $url = trim(str_replace(["\0", "\r", "\n", "\t"], '', $url));
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return mb_substr($url, 0, 500);
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return mb_substr($url, 0, 500);
    }
}
