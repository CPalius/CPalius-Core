<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Core\Settings\SettingsRegistry;

/**
 * Typed reader for the module's settings. Every numeric value is clamped here
 * so an operator typing 100000 into a quota field cannot turn the inbox into
 * a denial-of-service tool.
 */
final class MessagesConfig
{
    public const PRIVACY_EVERYONE = 'everyone';
    public const PRIVACY_CONTACTS = 'contacts';
    public const PRIVACY_NOBODY = 'nobody';

    public const PRIVACY_OPTIONS = [
        self::PRIVACY_EVERYONE,
        self::PRIVACY_CONTACTS,
        self::PRIVACY_NOBODY,
    ];

    public function __construct(
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('messages.enabled', true);
    }

    public function allowNewThreads(): bool
    {
        return (bool) $this->settings->get('messages.allow_new_threads', true);
    }

    public function threadsPerPage(): int
    {
        return $this->clamp($this->settings->get('messages.threads_per_page', 20), 5, 50, 20);
    }

    public function messagesPerPage(): int
    {
        return $this->clamp($this->settings->get('messages.messages_per_page', 30), 10, 100, 30);
    }

    public function maxBodyLength(): int
    {
        return $this->clamp($this->settings->get('messages.max_body_length', 5000), 200, 20000, 5000);
    }

    public function minAccountAgeHours(): int
    {
        $value = $this->settings->get('messages.min_account_age_hours', 0);

        return is_numeric($value) ? max(0, min(720, (int) $value)) : 0;
    }

    /**
     * 0 means "no cap".
     */
    public function hourlySendLimit(): int
    {
        return $this->quota($this->settings->get('messages.hourly_send_limit', 20), 20);
    }

    public function dailySendLimit(): int
    {
        return $this->quota($this->settings->get('messages.daily_send_limit', 50), 50);
    }

    public function dailyNewThreadLimit(): int
    {
        return $this->quota($this->settings->get('messages.daily_new_thread_limit', 10), 10);
    }

    public function floodWindow(): int
    {
        return $this->clamp($this->settings->get('messages.flood_window', 60), 10, 3600, 60);
    }

    public function floodLimit(): int
    {
        return $this->quota($this->settings->get('messages.flood_limit', 8), 8);
    }

    public function defaultAllowFrom(): string
    {
        $value = (string) $this->settings->get('messages.default_allow_from', self::PRIVACY_EVERYONE);

        return \in_array($value, self::PRIVACY_OPTIONS, true) ? $value : self::PRIVACY_EVERYONE;
    }

    public function notificationsEnabled(): bool
    {
        return (bool) $this->settings->get('messages.notifications_enabled', true);
    }

    public function heroTitle(): string
    {
        return trim((string) $this->settings->get('messages.hero_title', ''));
    }

    public function heroDescription(): string
    {
        return trim((string) $this->settings->get('messages.hero_description', ''));
    }

    private function quota(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? max(0, min(1000, (int) $value)) : $fallback;
    }

    private function clamp(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }
}
