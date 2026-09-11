<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Content moderation. Carrier class — attributes read at compile time.
 */
#[CpSetting(key: 'content.moderation.enabled_types', label: 'settings.core.moderation_enabled_types', type: 'text', default: '', group: 'content')]
#[CpSetting(key: 'content.moderation.workflow', label: 'settings.core.moderation_workflow', type: 'text', default: 'editorial', group: 'content')]
final class ModerationSettings
{
}
