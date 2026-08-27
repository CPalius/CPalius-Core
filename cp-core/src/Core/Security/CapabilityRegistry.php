<?php

namespace App\Core\Security;

/**
 * Sistemdeki TÜM geçerli yeteneklerin (capability) tek doğruluk kaynağı.
 *
 * CPalius'ta ROLE_* sabit kontrolü yasaktır (bkz. CPaliusVoter); bunun
 * yerine her yetki kararı "system.module.manage", "node.post.edit.own"
 * gibi serbest metin yeteneklere karşı verilir. Bu registry olmadan bir
 * rol config'inde yazım hatası ("nod.post.create" gibi) sessizce hiçbir
 * şey yapmayan, güvenlik açısından tehlikeli bir izin üretebilirdi.
 *
 * Fail-Safe kuralı: register() edilmemiş bir yetenek sorgulandığında
 * (has() ile) false dönülür — "bilinmeyen yetenek = izin yok" varsayılan
 * tutumu benimsenir, asla "bilinmeyen yetenek = izin ver" olmaz.
 */
final class CapabilityRegistry
{
    /** @var array<string, string> yetenek adı => tanımlayan kaynak (ör. "core", "Modules\Blog\BlogModule") */
    private array $capabilities = [];

    /**
     * Çekirdek veya bir modül, kendi yeteneklerini burada bildirir.
     * Aynı isim birden çok kez register edilirse (ör. cache warmup sırasında
     * tekrar çalışma), idempotent olarak üzerine yazılır — hata fırlatmaz.
     */
    public function register(string $capability, string $source = 'core'): void
    {
        $this->capabilities[$capability] = $source;
    }

    /**
     * @param iterable<string> $capabilities
     */
    public function registerMany(iterable $capabilities, string $source = 'core'): void
    {
        foreach ($capabilities as $capability) {
            $this->register($capability, $source);
        }
    }

    /**
     * Fail-Safe: registry'de kayıtlı olmayan bir yetenek için her zaman
     * false döner. CPaliusVoter, bir rolün bu yeteneğe sahip olup olmadığını
     * kontrol etmeden ÖNCE bu metotla yeteneğin gerçekten var olduğunu
     * doğrulamalıdır.
     */
    public function has(string $capability): bool
    {
        return isset($this->capabilities[$capability]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->capabilities);
    }

    public function getSource(string $capability): ?string
    {
        return $this->capabilities[$capability] ?? null;
    }
}
