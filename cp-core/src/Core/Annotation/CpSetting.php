<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Compile-time declaration of a single setting, collected by SettingsRegistrationPass.
 * Repeatable: stacked on an empty carrier class (see App\Core\Settings\Definitions\CoreSettings).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class CpSetting
{
    /** Settings screen tabs. A null scope is auto-detected from $module. */
    public const SCOPE_CORE = 'core';
    public const SCOPE_MODULE = 'module';
    public const SCOPE_PLUGIN = 'plugin';

    /**
     * @param string                $key          Unique identifier, e.g. "core.site_name".
     * @param string                $label        label shown in the admin screen
     * @param string                $type         'text'|'checkbox'|'select'|'textarea'|'integer'|'password'
     * @param mixed                 $default      returned when no database row exists
     * @param array<string, string> $variants     options for 'select' (value => label)
     * @param string                $module       owning module/plugin id; "core" for core settings
     * @param string                $group        section this setting is grouped under
     * @param bool                  $translatable Store the value as a {"tr": "...", "en": "..."} JSON map
     *                                            resolved against the active locale. Only honoured for 'text' and 'textarea'.
     * @param string|null           $scope        Settings tab: core|module|plugin. Null auto-detects from
     *                                            $module (core => core, registered plugin name => plugin, otherwise module).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly mixed $default = null,
        public readonly array $variants = [],
        public readonly string $module = 'core',
        public readonly string $group = 'general',
        public readonly bool $translatable = false,
        public readonly ?string $scope = null,
    ) {
    }
}
