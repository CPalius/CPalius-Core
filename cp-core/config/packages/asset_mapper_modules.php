<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Maps only active modules' Resources/assets into AssetMapper.
 * Paths are derived from active_modules.php so a ZIP-installed module
 * is picked up after activation without editing this file.
 */
return static function (ContainerConfigurator $container): void {
    $packagesDir = __DIR__;
    $configDir = \dirname($packagesDir);
    $projectDir = \dirname($configDir, 2);
    $activeFile = $configDir.'/active_modules.php';
    $modulesDir = $projectDir.'/cp-content/modules';

    if (!is_file($activeFile) || !is_dir($modulesDir)) {
        return;
    }

    try {
        $active = require $activeFile;
    } catch (\Throwable) {
        return;
    }

    if (!\is_array($active)) {
        return;
    }

    $paths = [];
    foreach ($active as $class) {
        if (!\is_string($class) || !str_starts_with($class, 'Modules\\')) {
            continue;
        }

        $dirName = explode('\\', $class)[1] ?? '';
        if ($dirName === '') {
            continue;
        }

        $relative = 'cp-content/modules/'.$dirName.'/Resources/assets';
        if (is_dir($projectDir.'/'.$relative)) {
            $paths[] = $relative.'/';
        }
    }

    if ($paths === []) {
        return;
    }

    $container->extension('framework', [
        'asset_mapper' => [
            'paths' => $paths,
        ],
    ]);
};
