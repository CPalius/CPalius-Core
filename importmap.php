<?php

declare(strict_types=1);

/**
 * Returns the importmap for this application.
 *
 * Core entrypoints live here. Module JS is merged from each active module's
 * Resources/config/importmap.php — never edit this file for a feature module.
 */
use App\Core\Module\ModuleImportmapLoader;

$core = [
    'app' => [
        'path' => './cp-core/assets/app.js',
        'entrypoint' => true,
    ],
    'aacp-dashboard' => [
        'path' => './cp-core/assets/aacp-dashboard.js',
        'entrypoint' => true,
    ],
    'chart.js' => [
        'version' => '4.4.7',
    ],
    'chart.js/auto' => [
        'version' => '4.4.7',
    ],
    '@kurkle/color' => [
        'version' => '0.3.4',
    ],
    'aacp-plugins' => [
        'path' => './cp-core/assets/aacp-plugins.js',
        'entrypoint' => true,
    ],
    'aacp-cache-rebuild' => [
        'path' => './cp-core/assets/aacp-cache-rebuild.js',
        'entrypoint' => true,
    ],
    'aacp-performance' => [
        'path' => './cp-core/assets/aacp-performance.js',
        'entrypoint' => true,
    ],
    'aacp-storage' => [
        'path' => './cp-core/assets/aacp-storage.js',
        'entrypoint' => true,
    ],
    'aacp-api-keys' => [
        'path' => './cp-core/assets/aacp-api-keys.js',
        'entrypoint' => true,
    ],
    'aacp-resource-form' => [
        'path' => './cp-core/assets/aacp-resource-form.js',
        'entrypoint' => true,
    ],
    'aacp-localization' => [
        'path' => './cp-core/assets/aacp-localization.js',
        'entrypoint' => true,
    ],
    'aacp-theme-editor' => [
        'path' => './cp-core/assets/aacp-theme-editor.js',
        'entrypoint' => true,
    ],
    'studio-quick-cache-clear' => [
        'path' => './cp-core/assets/studio-quick-cache-clear.js',
        'entrypoint' => true,
    ],
    'cp-editor-init' => [
        'path' => './cp-core/assets/cp-editor-init.js',
        'entrypoint' => true,
    ],
    'aacp-user-roles' => [
        'path' => './cp-core/assets/aacp-user-roles.js',
        'entrypoint' => true,
    ],
    'studio-dashboard' => [
        'path' => './cp-core/assets/studio-dashboard.js',
        'entrypoint' => true,
    ],
    'sortablejs' => [
        'version' => '1.15.7',
    ],
    'ckeditor5' => [
        'path' => './cp-core/assets/vendor/ckeditor5-custom/ckeditor5.js',
    ],
    'ckeditor5/dist/ckeditor5.css' => [
        'path' => './cp-core/assets/vendor/ckeditor5-custom/ckeditor5.css',
        'type' => 'css',
    ],
    'ckeditor5/translations/tr' => [
        'path' => './cp-core/assets/vendor/ckeditor5-custom/tr.js',
    ],
    'flowbite' => [
        'version' => '4.0.2',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'flowbite-datepicker' => [
        'version' => '2.0.0',
    ],
];

return ModuleImportmapLoader::merge($core, __DIR__);
