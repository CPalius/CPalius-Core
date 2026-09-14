<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Remote storage targets, media offload, and the image CDN.
 *
 * module is 'storage', which is listed in SettingDefinition::HIDDEN_MODULES, so
 * none of this appears in the generic System Settings tables. That is not
 * tidiness: the storage screen gates "use this target" behind a live write test
 * (StorageTargetRegistry::enable), and a generic table that let an operator
 * change the secret key without re-testing would hand them a backup
 * destination that looks configured and silently accepts nothing.
 *
 * Credentials are type 'password', which SettingSecretCodec seals at rest — a
 * shared database dump of cp_settings must not be a bucket takeover.
 *
 * One credential block per provider, shared by every consumer. Media offload
 * and backup shipping each pick a target by name rather than carrying their own
 * copy of the same keys, so moving a site from S3 to R2 is one select, not two
 * re-typed secrets.
 */
#[CpSetting(key: 'storage.s3.endpoint', label: 'aacp.storage.field.endpoint', type: 'text', default: '', module: 'storage', group: 's3')]
#[CpSetting(key: 'storage.s3.region', label: 'aacp.storage.field.region', type: 'text', default: 'eu-central-1', module: 'storage', group: 's3')]
#[CpSetting(key: 'storage.s3.bucket', label: 'aacp.storage.field.bucket', type: 'text', default: '', module: 'storage', group: 's3')]
#[CpSetting(key: 'storage.s3.access_key', label: 'aacp.storage.field.access_key', type: 'text', default: '', module: 'storage', group: 's3')]
#[CpSetting(key: 'storage.s3.secret_key', label: 'aacp.storage.field.secret_key', type: 'password', default: '', module: 'storage', group: 's3')]
#[CpSetting(key: 'storage.s3.prefix', label: 'aacp.storage.field.prefix', type: 'text', default: '', module: 'storage', group: 's3')]
#[CpSetting(key: 'storage.s3.path_style', label: 'aacp.storage.field.path_style', type: 'checkbox', default: false, module: 'storage', group: 's3')]

// R2 has no regions; "auto" is the literal string Cloudflare expects in the
// credential scope, and path_style is not offered because R2 only speaks it.
#[CpSetting(key: 'storage.r2.endpoint', label: 'aacp.storage.field.endpoint', type: 'text', default: '', module: 'storage', group: 'r2')]
#[CpSetting(key: 'storage.r2.region', label: 'aacp.storage.field.region', type: 'text', default: 'auto', module: 'storage', group: 'r2')]
#[CpSetting(key: 'storage.r2.bucket', label: 'aacp.storage.field.bucket', type: 'text', default: '', module: 'storage', group: 'r2')]
#[CpSetting(key: 'storage.r2.access_key', label: 'aacp.storage.field.access_key', type: 'text', default: '', module: 'storage', group: 'r2')]
#[CpSetting(key: 'storage.r2.secret_key', label: 'aacp.storage.field.secret_key', type: 'password', default: '', module: 'storage', group: 'r2')]
#[CpSetting(key: 'storage.r2.prefix', label: 'aacp.storage.field.prefix', type: 'text', default: '', module: 'storage', group: 'r2')]

#[CpSetting(key: 'storage.ftp.host', label: 'aacp.storage.field.host', type: 'text', default: '', module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.port', label: 'aacp.storage.field.port', type: 'integer', default: 21, module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.username', label: 'aacp.storage.field.username', type: 'text', default: '', module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.password', label: 'aacp.storage.field.password', type: 'password', default: '', module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.path', label: 'aacp.storage.field.path', type: 'text', default: '', module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.passive', label: 'aacp.storage.field.passive', type: 'checkbox', default: true, module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.ssl', label: 'aacp.storage.field.ssl', type: 'checkbox', default: false, module: 'storage', group: 'ftp')]
#[CpSetting(key: 'storage.ftp.timeout', label: 'aacp.storage.field.timeout', type: 'integer', default: 30, module: 'storage', group: 'ftp')]

// 'off' keeps every byte on this server, which is what an installation that
// never opens this screen must keep doing.
#[CpSetting(key: 'storage.media.target', label: 'aacp.storage.field.media_target', type: 'select', default: 'off', variants: ['off' => 'aacp.storage.target.off', 's3' => 'aacp.storage.target.s3', 'r2' => 'aacp.storage.target.r2', 'ftp' => 'aacp.storage.target.ftp'], module: 'storage', group: 'media')]
#[CpSetting(key: 'storage.media.sweep_enabled', label: 'aacp.storage.field.sweep_enabled', type: 'checkbox', default: false, module: 'storage', group: 'media')]
#[CpSetting(key: 'storage.media.sweep_batch', label: 'aacp.storage.field.sweep_batch', type: 'integer', default: 200, module: 'storage', group: 'media')]

#[CpSetting(key: 'storage.backup.target', label: 'aacp.storage.field.backup_target', type: 'select', default: 'off', variants: ['off' => 'aacp.storage.target.local_only', 's3' => 'aacp.storage.target.s3', 'r2' => 'aacp.storage.target.r2', 'ftp' => 'aacp.storage.target.ftp'], module: 'storage', group: 'backup')]
#[CpSetting(key: 'storage.backup.prefix', label: 'aacp.storage.field.backup_prefix', type: 'text', default: 'cpalius-backups', module: 'storage', group: 'backup')]
#[CpSetting(key: 'storage.backup.keep_local', label: 'aacp.storage.field.keep_local', type: 'checkbox', default: true, module: 'storage', group: 'backup')]
#[CpSetting(key: 'storage.backup.remote_retention', label: 'aacp.storage.field.remote_retention', type: 'integer', default: 10, module: 'storage', group: 'backup')]

// The CDN is deliberately independent of offload. The common case — a pull-zone
// in front of this origin (Cloudflare, BunnyCDN, Fastly) — needs no upload at
// all, only a different hostname in the markup. Coupling the two would have
// forced those operators to configure a bucket they will never use.
#[CpSetting(key: 'cdn.enabled', label: 'aacp.storage.field.cdn_enabled', type: 'checkbox', default: false, module: 'storage', group: 'cdn')]
#[CpSetting(key: 'cdn.base_url', label: 'aacp.storage.field.cdn_base_url', type: 'text', default: '', module: 'storage', group: 'cdn')]
#[CpSetting(key: 'cdn.images_only', label: 'aacp.storage.field.cdn_images_only', type: 'checkbox', default: true, module: 'storage', group: 'cdn')]
final class StorageSettings
{
}
