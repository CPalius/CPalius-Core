<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Bir performans backend'i (Redis/Memcached/Varnish/PageSpeed) için tek bir
 * bağlantı testinin salt-veri sonucu. Üç durum kasıtlı olarak ayrıştırılır
 * (bkz. PerformanceBackendCheckerInterface): 'not_installed' ve
 * 'connection_failed' arasındaki fark, kullanıcıya "bu sunucuda hiç yok" ile
 * "var ama şu an ulaşılamıyor" mesajlarını doğru vermek için önemlidir —
 * ikisi de PerformanceBackendRegistry::enable() tarafından aktivasyonu
 * engeller, ama arayüz farklı bir mesaj gösterir.
 *
 * $messageKey BİLİNÇLİ OLARAK HAZIR bir metin DEĞİL, bir çeviri anahtarıdır
 * (ör. 'aacp.performance.probe.redis.not_installed') — probe sınıfları
 * (Redis/Memcached/Varnish/PageSpeed) hiçbir zaman kullanıcıya gösterilecek
 * dil-bağımlı bir string üretmez, sadece "hangi durum" bilgisini taşır.
 * Bu sayede sonuç PerformanceBackendStatus'a KEY olarak yazılır, dil
 * değiştiğinde (session'daki aacp locale) aynı satır farklı dilde
 * görüntülenebilir — DB'ye çevrilmiş metin gömülmez.
 */
final class PerformanceCheckResult
{
    /**
     * @param 'ok'|'not_installed'|'connection_failed'|'misconfigured' $status
     * @param array<string, string|int|float> $messageParams ICU MessageFormat
     *   parametreleri (ör. ['host' => '127.0.0.1', 'port' => 6379]) —
     *   çeviri anahtarının içine görüntüleme anında |trans(params) ile enjekte edilir.
     * @param array<string, mixed> $details
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $messageKey,
        public readonly array $messageParams = [],
        public readonly ?float $latencyMs = null,
        public readonly array $details = [],
    ) {
    }

    /**
     * @param array<string, string|int|float> $messageParams
     * @param array<string, mixed> $details
     */
    public static function ok(string $messageKey, array $messageParams, float $latencyMs, array $details = []): self
    {
        return new self(true, 'ok', $messageKey, $messageParams, $latencyMs, $details);
    }

    /**
     * @param array<string, string|int|float> $messageParams
     */
    public static function notInstalled(string $messageKey, array $messageParams = []): self
    {
        return new self(false, 'not_installed', $messageKey, $messageParams);
    }

    /**
     * @param array<string, string|int|float> $messageParams
     */
    public static function connectionFailed(string $messageKey, array $messageParams = [], ?float $latencyMs = null): self
    {
        return new self(false, 'connection_failed', $messageKey, $messageParams, $latencyMs);
    }

    public static function misconfigured(string $messageKey): self
    {
        return new self(false, 'misconfigured', $messageKey);
    }

    /**
     * @return array{success: bool, status: string, messageKey: string, messageParams: array<string, mixed>, latencyMs: ?float, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'messageKey' => $this->messageKey,
            'messageParams' => $this->messageParams,
            'latencyMs' => $this->latencyMs,
            'details' => $this->details,
        ];
    }
}
