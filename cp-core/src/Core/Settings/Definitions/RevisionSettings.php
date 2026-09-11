<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Node revision behaviour. Carrier class — attributes read at compile time.
 */
#[CpSetting(key: 'content.revisions.enabled', label: 'settings.core.revisions_enabled', type: 'checkbox', default: true, group: 'content')]
#[CpSetting(key: 'content.revisions.max_per_node', label: 'settings.core.revisions_max_per_node', type: 'integer', default: 25, group: 'content')]
final class RevisionSettings
{
}
