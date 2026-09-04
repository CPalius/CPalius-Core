<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Redis/Memcached/Varnish/PageSpeed connection settings. Timeouts are 'text' (no float type).
 * module is 'performance' so AACP System Management (module===core) does not duplicate RMVP fields.
 */
#[CpSetting(key: 'performance.redis.host', label: 'Redis Sunucu Adresi', type: 'text', default: '127.0.0.1', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.port', label: 'Redis Port', type: 'integer', default: 6379, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.password', label: 'Redis Parola', type: 'text', default: '', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.timeout', label: 'Redis Zaman Aşımı (sn)', type: 'text', default: '1.5', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.host', label: 'Memcached Sunucu Adresi', type: 'text', default: '127.0.0.1', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.port', label: 'Memcached Port', type: 'integer', default: 11211, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.timeout', label: 'Memcached Zaman Aşımı (sn)', type: 'text', default: '1.5', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.backend_url', label: 'Varnish Kontrol URL\'si', type: 'text', default: 'http://127.0.0.1/', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.port', label: 'Varnish Port', type: 'integer', default: 6081, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.ttl', label: 'Varnish Önbellek Süresi (sn)', type: 'integer', default: 120, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.excludes', label: 'Varnish Hariç Tutulan Yollar', type: 'textarea', default: "/aacp\n/admin\n/login\n/logout\n/hesap\n/api\n/_fragment\n/_profiler\n/_wdt", module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.timeout', label: 'Varnish Zaman Aşımı (sn)', type: 'text', default: '2', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.pagespeed.check_url', label: 'PageSpeed Kontrol URL\'si', type: 'text', default: 'http://127.0.0.1/', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.pagespeed.timeout', label: 'PageSpeed Zaman Aşımı (sn)', type: 'text', default: '2', module: 'performance', group: 'performance')]
final class PerformanceSettings
{
}
