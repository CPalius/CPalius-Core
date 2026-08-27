<?php

declare(strict_types=1);

namespace App\Core\Settings;

/**
 * #[CpSetting] ile işaretlenmiş TEK bir ayarın derleme zamanında çözülmüş,
 * değişmez tanımı. ResourceDefinition/MenuItemDefinition ile aynı ruhta:
 * saf veri taşıyıcısı, hiçbir davranış içermez.
 */
final class SettingDefinition
{
    /**
     * @param array<string, string> $variants
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default,
        public readonly array $variants,
        public readonly string $module,
        public readonly string $group,
    ) {
    }
}
