<?php

declare(strict_types=1);

namespace App\Core\Localization\Service;

use App\Core\Localization\LocaleProvider;
use App\Entity\User;

/**
 * Which language one account should be written to.
 *
 * Registration already records the choice (`User::$data['locale']`), but until
 * now nothing read it back: every mail was rendered in whatever locale the
 * *request that triggered it* happened to carry. That is the requester's
 * language, not the recipient's — an English-speaking admin approving a Turkish
 * member mailed them in English, and a queue worker (no request at all) mailed
 * everybody in the kernel default.
 *
 * Clamped through LocaleProvider so a locale that was active at registration
 * and has since been switched off cannot produce a mail with no catalogue.
 */
final class UserLocaleResolver
{
    public const DATA_KEY = 'locale';

    public function __construct(
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function resolve(?User $user): string
    {
        if (!$user instanceof User) {
            return $this->localeProvider->getDefaultCode();
        }

        $stored = $user->getDataValue(self::DATA_KEY);

        return $this->localeProvider->resolve(\is_string($stored) ? $stored : null);
    }

    /**
     * True when the account has an explicit, still-active preference — the
     * profile form uses this to decide between "your language" and "site
     * default".
     */
    public function hasExplicitPreference(User $user): bool
    {
        $stored = $user->getDataValue(self::DATA_KEY);

        return \is_string($stored) && $this->localeProvider->isSupported($stored);
    }

    public function remember(User $user, ?string $locale): string
    {
        $resolved = $this->localeProvider->resolve($locale);
        $user->setDataValue(self::DATA_KEY, $resolved);

        return $resolved;
    }
}
