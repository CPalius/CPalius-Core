<?php

declare(strict_types=1);

namespace Modules\Importer\Migration;

/**
 * Every imported member needs an address: UserDestination matches and
 * recovers on email. XenForo (and MyBB) boards are full of accounts with
 * none, and skipping those people is what leaves their posts as unlinked
 * names. A reserved .invalid address is unique, not deliverable, and
 * cannot collide with a real member.
 */
final class ImportedAccountEmail
{
    public static function resolve(string $email, string $system, string $sourceId): string
    {
        $email = trim($email);

        if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $sourceId) ?: '0';

        return sprintf('imported-%s-%s@invalid.invalid', $system, $safe);
    }

    public static function isPlaceholder(string $email): bool
    {
        return str_ends_with($email, '@invalid.invalid');
    }
}
