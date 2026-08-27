<?php

declare(strict_types=1);

namespace App\Core\Localization;

/**
 * Translation Explorer tablosunun tek bir satırı: bir anahtarın hem TR
 * hem EN karşılığı ve bu anahtarın hangi dosya grubuna ait olduğu
 * ("core" ya da "module:<ModuleName>") — inline düzenleme ve export bu
 * grup bilgisiyle doğru dosyaya geri yazar.
 */
final class TranslationEntry
{
    public function __construct(
        public readonly string $group,
        public readonly string $key,
        public readonly string $tr,
        public readonly string $en,
    ) {
    }

    /**
     * @return array{group: string, key: string, tr: string, en: string}
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'key' => $this->key,
            'tr' => $this->tr,
            'en' => $this->en,
        ];
    }
}
