<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Resolve Doctrine ORM mapping for active modules that have an Entity/ directory.
 * Namespace Modules\{Name}\Entity, alias Modules{Name}; modules without an Entity/
 * directory (e.g. Media) are skipped.
 */
final class ModuleEntityMappingResolver
{
    /**
     * @return array{namespace: string, alias: string, dir: string}|null
     */
    public static function resolve(string $moduleClass): ?array
    {
        if (!class_exists($moduleClass)) {
            return null;
        }

        $parts = explode('\\', $moduleClass);
        if (($parts[0] ?? '') !== 'Modules' || !isset($parts[1]) || $parts[1] === '') {
            return null;
        }

        $moduleName = $parts[1];
        $reflection = new \ReflectionClass($moduleClass);
        $entityDir = \dirname($reflection->getFileName()).'/Entity';

        if (!is_dir($entityDir)) {
            return null;
        }

        $namespace = 'Modules\\'.$moduleName.'\\Entity';

        return [
            'namespace' => $namespace,
            'alias' => 'Modules'.$moduleName,
            'dir' => $entityDir,
        ];
    }

    /**
     * @param list<class-string> $healthyModuleClasses
     *
     * @return list<array{namespace: string, alias: string, dir: string}>
     */
    public static function resolveMany(array $healthyModuleClasses): array
    {
        $mappings = [];

        foreach ($healthyModuleClasses as $moduleClass) {
            if (!\is_string($moduleClass) || $moduleClass === '') {
                continue;
            }

            $mapping = self::resolve($moduleClass);
            if ($mapping !== null) {
                $mappings[] = $mapping;
            }
        }

        return $mappings;
    }
}
