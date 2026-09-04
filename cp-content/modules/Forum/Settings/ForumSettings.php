<?php

declare(strict_types=1);

namespace Modules\Forum\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Forum settings listed under AACP Module Settings.
 */
#[CpSetting(
    key: 'forum.threads_per_page',
    label: 'Sayfa Başına Konu (forum_threads_per_page)',
    type: 'integer',
    default: 30,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.posts_per_page',
    label: 'Sayfa Başına İleti (forum_posts_per_page)',
    type: 'integer',
    default: 15,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.allow_guest_view',
    label: 'Ziyaretçilere Forum Açık (forum_allow_guest_view)',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.fast_reply_enabled',
    label: 'Hızlı Yanıt Kutusu (forum_fast_reply_enabled)',
    type: 'checkbox',
    default: true,
    module: 'forum',
    group: 'forum.engine',
)]
#[CpSetting(
    key: 'forum.edit_time_limit',
    label: 'Mesaj Düzenleme Süresi — dakika (forum_edit_time_limit)',
    type: 'integer',
    default: 15,
    module: 'forum',
    group: 'forum.engine',
)]
final class ForumSettings
{
}
