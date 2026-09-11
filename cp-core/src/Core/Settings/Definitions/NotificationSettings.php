<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Core notification kill switches and digest policy.
 */
#[CpSetting(key: 'notification.enabled', label: 'settings.core.notification_enabled', type: 'checkbox', default: true, group: 'notification')]
#[CpSetting(key: 'notification.digest.enabled', label: 'settings.core.notification_digest_enabled', type: 'checkbox', default: true, group: 'notification')]
final class NotificationSettings
{
}
