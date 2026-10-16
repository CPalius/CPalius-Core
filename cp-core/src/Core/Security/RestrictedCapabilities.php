<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Capabilities that hand over the site itself, not just a feature: code and package installation, backups
 * (the database and secrets leave the server), user and permission management, API keys, outbound hooks,
 * data export, running scripts on the public homepage. Drupal marks these "restrict access"; here they get a
 * warning badge where roles and overrides are edited, and cp:doctor reports any non-admin role that holds one.
 */
final class RestrictedCapabilities
{
    /** Can take over the site or read everything on it. */
    private const CRITICAL = [
        'system.update.manage',
        'system.module.manage',
        'system.backup.manage',
        'system.security.manage',
        'system.users.manage',
        'system.settings.manage',
        'system.api.manage',
        'system.hooks.manage',
        'system.webhooks.manage',
        'system.data.export',
        'system.homepage.script',
        'admin.rebuild.run',
        'importer.run',
        'forum.permissions.manage',
        'ai.manage',
    ];

    /** Powerful, but bounded to one area. */
    private const SENSITIVE = [
        'system.aacp.access',
        'admin.access',
        'system.cron.manage',
        'system.queue.manage',
        'system.logs.purge',
        'system.trash.purge',
        'system.performance.manage',
        'system.localization.manage',
        'system.fields.manage',
        'system.users.view',
        'dnstools.manage',
        'ai_user.manage',
        'forum.user.manage',
        'marketplace.trust.adjust',
        'messages.quota.exempt',
        'visitorstats.manage',
    ];

    public static function level(string $capability): ?string
    {
        if (\in_array($capability, self::CRITICAL, true)) {
            return 'critical';
        }
        if (\in_array($capability, self::SENSITIVE, true) || str_ends_with($capability, '.settings.manage')) {
            return 'sensitive';
        }

        return null;
    }

    public static function isRestricted(string $capability): bool
    {
        return self::level($capability) !== null;
    }
}
