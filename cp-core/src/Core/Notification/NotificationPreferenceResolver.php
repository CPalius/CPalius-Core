<?php

declare(strict_types=1);

namespace App\Core\Notification;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;

/**
 * Resolves which channels fire for a given event × user preference matrix.
 *
 * @phpstan-type ChannelDecision list<string>
 */
final class NotificationPreferenceResolver
{
    public const PREF_MAIL_ENABLED = 'notif_mail_enabled';
    public const PREF_MAIL_MODE = 'notif_mail_mode';

    public const MODE_INSTANT = 'instant';
    public const MODE_DAILY = 'daily';
    public const MODE_WEEKLY = 'weekly';

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    /**
     * @return ChannelDecision channel keys to deliver on
     */
    public function channelsFor(User $user, NotificationTypeDefinition $type): array
    {
        if (!(bool) $this->settings->get('notification.enabled', true)) {
            return [];
        }

        if ($type->preferenceKey !== null && !$this->prefOn($user, $type->preferenceKey)) {
            return [];
        }

        $channels = [];
        foreach ($type->defaultChannels as $channel) {
            if ($channel === 'in_app') {
                $channels[] = 'in_app';
                continue;
            }

            if ($channel === 'mail_instant' || $channel === 'mail_digest') {
                $mailChannel = $this->resolveMailChannel($user);
                if ($mailChannel !== null) {
                    $channels[] = $mailChannel;
                }
            }
        }

        return array_values(array_unique($channels));
    }

    public function mailMode(User $user): string
    {
        $mode = (string) $user->getDataValue(self::PREF_MAIL_MODE, self::MODE_INSTANT);

        return \in_array($mode, [self::MODE_INSTANT, self::MODE_DAILY, self::MODE_WEEKLY], true)
            ? $mode
            : self::MODE_INSTANT;
    }

    public function currentBucket(User $user, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $mode = $this->mailMode($user);

        return match ($mode) {
            self::MODE_WEEKLY => 'weekly:'.$now->format('o-\WW'),
            default => 'daily:'.$now->format('Y-m-d'),
        };
    }

    /**
     * Buckets that are fully closed (previous day / previous ISO week).
     *
     * @return list<string>
     */
    public function closedBuckets(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $yesterday = $now->modify('-1 day');
        $lastWeek = $now->modify('-7 days');

        return [
            'daily:'.$yesterday->format('Y-m-d'),
            'weekly:'.$lastWeek->format('o-\WW'),
        ];
    }

    private function resolveMailChannel(User $user): ?string
    {
        if (!(bool) $this->settings->get('notification.digest.enabled', true)
            && $this->mailMode($user) !== self::MODE_INSTANT
        ) {
            // Digest disabled site-wide → fall back to instant if mail is on.
        }

        if (!$this->prefOn($user, self::PREF_MAIL_ENABLED)) {
            return null;
        }

        if (!(bool) $this->settings->get('mail.enabled', false)) {
            return null;
        }

        $mode = $this->mailMode($user);
        if ($mode !== self::MODE_INSTANT && (bool) $this->settings->get('notification.digest.enabled', true)) {
            return 'mail_digest';
        }

        return 'mail_instant';
    }

    private function prefOn(User $user, string $key): bool
    {
        $value = $user->getDataValue($key, true);

        return $value !== false && $value !== 0 && $value !== '0';
    }
}
