<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Validates a module's requires / conflicts / core_version matrix before activation.
 * Returns human-readable problems; an empty list means "safe to activate".
 */
final class ModuleDependencyChecker
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly string $modulesDir,
        private readonly string $coreVersion,
    ) {
    }

    /**
     * Problems blocking activation of $target, in report order.
     *
     * @return list<string>
     */
    public function checkActivation(ModuleManifest $target): array
    {
        $problems = [];
        $manifests = $this->manifests();
        $activeDirNames = $this->activeDirNames($manifests);

        $problems = [...$problems, ...$this->checkCoreVersion($target)];
        $problems = [...$problems, ...$this->checkRequires($target, $manifests, $activeDirNames)];
        $problems = [...$problems, ...$this->checkConflicts($target, $manifests, $activeDirNames)];

        return $problems;
    }

    /**
     * Active modules that would break if $target were deactivated.
     *
     * @return list<string>
     */
    public function checkDeactivation(ModuleManifest $target): array
    {
        $manifests = $this->manifests();
        $activeDirNames = $this->activeDirNames($manifests);
        $dependents = [];

        foreach ($manifests as $dirName => $manifest) {
            if ($dirName === $target->dirName || !\in_array($dirName, $activeDirNames, true)) {
                continue;
            }

            if ($this->resolveRequirement($manifest, $target->dirName, $manifests) !== null) {
                $dependents[] = sprintf('"%s" requires "%s".', $manifest->name, $target->name);
            }
        }

        return $dependents;
    }

    /**
     * @return list<string>
     */
    private function checkCoreVersion(ModuleManifest $target): array
    {
        if ($target->coreVersion === null) {
            return [];
        }

        if ($this->satisfies($this->coreVersion, $target->coreVersion)) {
            return [];
        }

        return [sprintf(
            '"%s" requires CPalius core %s, but %s is installed.',
            $target->name,
            $target->coreVersion,
            $this->coreVersion,
        )];
    }

    /**
     * @param array<string, ModuleManifest> $manifests
     * @param list<string> $activeDirNames
     *
     * @return list<string>
     */
    private function checkRequires(ModuleManifest $target, array $manifests, array $activeDirNames): array
    {
        $problems = [];

        foreach ($target->requires as $requirement => $constraint) {
            $provider = $this->findProvider($requirement, $manifests);

            if ($provider === null) {
                $problems[] = sprintf('"%s" requires "%s", which is not installed.', $target->name, $requirement);
                continue;
            }

            if (!\in_array($provider->dirName, $activeDirNames, true)) {
                $problems[] = sprintf('"%s" requires "%s", which is installed but not active.', $target->name, $provider->name);
                continue;
            }

            if (!$this->satisfies($provider->version, $constraint)) {
                $problems[] = sprintf(
                    '"%s" requires "%s" %s, but version %s is active.',
                    $target->name,
                    $provider->name,
                    $constraint,
                    $provider->version,
                );
            }
        }

        return $problems;
    }

    /**
     * @param array<string, ModuleManifest> $manifests
     * @param list<string> $activeDirNames
     *
     * @return list<string>
     */
    private function checkConflicts(ModuleManifest $target, array $manifests, array $activeDirNames): array
    {
        $problems = [];

        foreach ($target->conflicts as $conflict => $constraint) {
            $other = $this->findProvider($conflict, $manifests);

            if ($other === null || !\in_array($other->dirName, $activeDirNames, true)) {
                continue;
            }

            if ($this->satisfies($other->version, $constraint)) {
                $problems[] = sprintf(
                    '"%s" conflicts with the active module "%s" (%s).',
                    $target->name,
                    $other->name,
                    $other->version,
                );
            }
        }

        return $problems;
    }

    /**
     * Resolves a requirement name against directory names, display names and
     * "provides" tags, so manifests can depend on a capability rather than a module.
     *
     * @param array<string, ModuleManifest> $manifests
     */
    private function findProvider(string $requirement, array $manifests): ?ModuleManifest
    {
        foreach ($manifests as $manifest) {
            if (strcasecmp($manifest->dirName, $requirement) === 0 || strcasecmp($manifest->name, $requirement) === 0) {
                return $manifest;
            }
        }

        foreach ($manifests as $manifest) {
            foreach ($manifest->provides as $tag) {
                if (strcasecmp($tag, $requirement) === 0) {
                    return $manifest;
                }
            }
        }

        return null;
    }

    /**
     * The constraint $manifest declares on $requirement, or null when it declares none.
     *
     * @param array<string, ModuleManifest> $manifests
     */
    private function resolveRequirement(ModuleManifest $manifest, string $requirement, array $manifests): ?string
    {
        foreach ($manifest->requires as $name => $constraint) {
            $provider = $this->findProvider($name, $manifests);

            if ($provider !== null && $provider->dirName === $requirement) {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * Supports "*", exact versions, "^x.y", "~x.y" and ">=x.y" style constraints.
     * Deliberately dependency-free: composer/semver is not required at boot time.
     */
    public function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (explode('||', $constraint) as $alternative) {
            if ($this->satisfiesSingle($version, trim($alternative))) {
                return true;
            }
        }

        return false;
    }

    private function satisfiesSingle(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (str_starts_with($constraint, '^')) {
            $base = substr($constraint, 1);
            $upper = $this->nextMajor($base);

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        if (str_starts_with($constraint, '~')) {
            $base = substr($constraint, 1);
            $upper = $this->nextMinor($base);

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        foreach (['>=', '<=', '!=', '>', '<', '='] as $operator) {
            if (str_starts_with($constraint, $operator)) {
                return version_compare($version, trim(substr($constraint, \strlen($operator))), $operator === '=' ? '==' : $operator);
            }
        }

        return version_compare($version, $constraint, '==');
    }

    private function nextMajor(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));

        return ((int) ($parts[0] ?? 0) + 1).'.0.0';
    }

    private function nextMinor(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));

        return ($parts[0] ?? 0).'.'.((int) ($parts[1] ?? 0) + 1).'.0';
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function manifests(): array
    {
        if (!is_dir($this->modulesDir)) {
            return [];
        }

        $manifests = [];

        foreach (scandir($this->modulesDir) ?: [] as $dirName) {
            if ($dirName === '.' || $dirName === '..') {
                continue;
            }

            $moduleDir = $this->modulesDir.'/'.$dirName;

            if (!is_dir($moduleDir)) {
                continue;
            }

            $manifest = ModuleManifest::fromDirectory($moduleDir);

            if ($manifest !== null) {
                $manifests[$dirName] = $manifest;
            }
        }

        return $manifests;
    }

    /**
     * @param array<string, ModuleManifest> $manifests
     *
     * @return list<string>
     */
    private function activeDirNames(array $manifests): array
    {
        $activeStatuses = [];

        foreach ($this->moduleRegistry->discoverAllModules() as $module) {
            $activeStatuses[$module['dirName']] = $module['status'];
        }

        $active = [];

        foreach (array_keys($manifests) as $dirName) {
            if (($activeStatuses[$dirName] ?? null) === 'active') {
                $active[] = $dirName;
            }
        }

        return $active;
    }
}
