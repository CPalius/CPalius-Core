<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Performans (Redis/Memcached/Varnish/PageSpeed) backend'lerinin bağlantı
 * ayarları. Bu sınıf kasıtlı olarak boştur — bkz. CoreSettings örneği ve
 * CpSetting docblock'u.
 *
 * "timeout" alanları bilinçli olarak 'text' tipindedir: SettingsRegistry'de
 * bir 'float' tipi yok ve alt-saniye değerler (ör. 1.5) gerekiyor; float'a
 * çevirme işi tüketim noktasında (PerformanceBackendRegistry) yapılır.
 *
 * "enabled" / son-test durumu BURADA YOKTUR — bkz. App\Entity\
 * PerformanceBackendStatus docblock'u: bu ayarlar serbestçe elle
 * düzenlenebilir, ama aktivasyon durumu asla elle set edilemez.
 *
 * module: 'performance' (module: 'core' DEĞİL): bu ayarlar zaten RMVP
 * (Performance) sayfasının kendi formunda (bkz. PerformanceController,
 * performance/index.html.twig) yönetiliyor. AACPPlaceholderController::
 * advancedManagement() "Sistem Yönetimi" sayfası module==='core' olan
 * tanımları filtreler — module burada 'core' bırakılsaydı aynı 11 alan
 * o sayfada da tekrar görünür, RMVP ile birebir çakışan bir mükerrerlik
 * oluştururdu. SettingsRegistry::get() anahtar bazlı okuduğu için (module
 * alanına bakmaz), bu değişiklik PerformanceBackendRegistry'nin okuma/
 * yazma davranışını hiç etkilemez.
 */
#[CpSetting(key: 'performance.redis.host', label: 'Redis Sunucu Adresi', type: 'text', default: '127.0.0.1', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.port', label: 'Redis Port', type: 'integer', default: 6379, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.password', label: 'Redis Parola', type: 'text', default: '', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.redis.timeout', label: 'Redis Zaman Aşımı (sn)', type: 'text', default: '1.5', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.host', label: 'Memcached Sunucu Adresi', type: 'text', default: '127.0.0.1', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.port', label: 'Memcached Port', type: 'integer', default: 11211, module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.memcached.timeout', label: 'Memcached Zaman Aşımı (sn)', type: 'text', default: '1.5', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.backend_url', label: 'Varnish Kontrol URL\'si', type: 'text', default: 'http://127.0.0.1/', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.varnish.timeout', label: 'Varnish Zaman Aşımı (sn)', type: 'text', default: '2', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.pagespeed.check_url', label: 'PageSpeed Kontrol URL\'si', type: 'text', default: 'http://127.0.0.1/', module: 'performance', group: 'performance')]
#[CpSetting(key: 'performance.pagespeed.timeout', label: 'PageSpeed Zaman Aşımı (sn)', type: 'text', default: '2', module: 'performance', group: 'performance')]
final class PerformanceSettings
{
}
