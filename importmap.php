<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
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
    'aacp-api-keys' => [
        'path' => './cp-core/assets/aacp-api-keys.js',
        'entrypoint' => true,
    ],
    'aacp-localization' => [
        'path' => './cp-core/assets/aacp-localization.js',
        'entrypoint' => true,
    ],
    'studio-quick-cache-clear' => [
        'path' => './cp-core/assets/studio-quick-cache-clear.js',
        'entrypoint' => true,
    ],
    'admin-post-form' => [
        'path' => './cp-core/assets/admin-post-form.js',
        'entrypoint' => true,
    ],
    'media-picker' => [
        'path' => './cp-core/assets/media-picker.js',
        'entrypoint' => true,
    ],
    'cp-editor-init' => [
        'path' => './cp-core/assets/cp-editor-init.js',
        'entrypoint' => true,
    ],
    'forum-editor-init' => [
        'path' => './cp-core/assets/forum-editor-init.js',
        'entrypoint' => true,
    ],
    'forum-dashboard' => [
        'path' => './cp-core/assets/forum-dashboard.js',
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
    'menu-sortable' => [
        'path' => './cp-core/assets/menu-sortable.js',
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
