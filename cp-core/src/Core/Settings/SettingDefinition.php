<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Compile-time resolved, immutable definition of a single #[CpSetting].
 * Pure data carrier, like ResourceDefinition and MenuItemDefinition.
 */
final class SettingDefinition
{
    /** $translatable is only honoured for these types (see CpSetting::$translatable). */
    private const TRANSLATABLE_TYPES = ['text', 'textarea'];

    /** Settings owned by these pseudo-modules are managed on their own screens. */
    public const HIDDEN_MODULES = ['studio_homepage', 'theme_manager', 'module_lifecycle'];

    /**
     * @param array<string, string> $variants
     * @param string|null $scope Null means "auto-detect", resolved by SettingScopeResolver.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default,
        public readonly array $variants,
        public readonly string $module,
        public readonly string $group,
        public readonly bool $translatable = false,
        public readonly ?string $scope = null,
    ) {
    }

    /**
     * Whether the value must actually be stored per locale.
     * A wrong declaration (e.g. translatable checkbox) is rejected here, in one place.
     */
    public function isTranslatable(): bool
    {
        return $this->translatable && \in_array($this->type, self::TRANSLATABLE_TYPES, true);
    }

    /** True when the attribute declared an explicit, valid scope. */
    public function hasExplicitScope(): bool
    {
        return \in_array($this->scope, [CpSetting::SCOPE_CORE, CpSetting::SCOPE_MODULE, CpSetting::SCOPE_PLUGIN], true);
    }

    /** Settings that own a dedicated admin screen must not appear in the generic tabs. */
    public function isHiddenFromSettingsScreen(): bool
    {
        return \in_array($this->module, self::HIDDEN_MODULES, true);
    }

    /**
     * @param array<string, string> $variants
     */
    public function withVariants(array $variants): self
    {
        return new self(
            $this->key,
            $this->label,
            $this->type,
            $this->default,
            $variants,
            $this->module,
            $this->group,
            $this->translatable,
            $this->scope,
        );
    }
}
