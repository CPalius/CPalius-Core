<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Redis/Memcached/Varnish/PageSpeed connection settings. Timeouts are 'text' (no float type).
 * module is 'performance' so AACP System Management (module===core) does not duplicate RMVP fields.
 */
#[CpSetting(key: 'performance.redis.host', label: 'aacp.performance.field.host', type: 'text', default: '127.0.0.1', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.port', label: 'aacp.performance.field.port', type: 'integer', default: 6379, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.password', label: 'aacp.performance.field.password', type: 'text', default: '', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.timeout', label: 'aacp.performance.field.timeout', type: 'text', default: '1.5', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.host', label: 'aacp.performance.field.host', type: 'text', default: '127.0.0.1', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.port', label: 'aacp.performance.field.port', type: 'integer', default: 11211, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.timeout', label: 'aacp.performance.field.timeout', type: 'text', default: '1.5', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.backend_url', label: 'aacp.performance.field.backend_url', type: 'text', default: 'http://127.0.0.1/', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.port', label: 'aacp.performance.field.port', type: 'integer', default: 6081, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.ttl', label: 'aacp.performance.field.ttl', type: 'integer', default: 120, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.excludes', label: 'aacp.performance.field.excludes', type: 'textarea', default: "/aacp\n/admin\n/login\n/logout\n/hesap\n/api\n/_fragment\n/_profiler\n/_wdt", module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.timeout', label: 'aacp.performance.field.timeout', type: 'text', default: '2', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.pagespeed.check_url', label: 'aacp.performance.field.check_url', type: 'text', default: 'http://127.0.0.1/', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.pagespeed.timeout', label: 'aacp.performance.field.timeout', type: 'text', default: '2', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.cpalius.ttl', label: 'aacp.performance.field.ttl', type: 'integer', default: 86400, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.cpalius.excludes', label: 'aacp.performance.field.excludes', type: 'textarea', default: "/aacp\n/admin\n/login\n/logout\n/hesap\n/api\n/_fragment\n/_profiler\n/_wdt", module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.cpalius.minify', label: 'aacp.performance.field.minify', type: 'checkbox', default: true, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.cpalius.compress_assets', label: 'aacp.performance.field.compress_assets', type: 'checkbox', default: true, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.cpalius.compress_images', label: 'aacp.performance.field.compress_images', type: 'checkbox', default: true, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.cpalius.shield', label: 'aacp.performance.field.shield', type: 'checkbox', default: false, module: 'performance', group: 'performance')]
final class PerformanceSettings
{
}
