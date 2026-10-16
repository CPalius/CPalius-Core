<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Module\ModuleRegistry;
use App\Core\Rebuild\RebuilderRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The rule behind the rebuild system: a module that stores content must be able to rebuild it.
 * Core cannot recount or re-index a table it has never heard of, so every active module with entities
 * must register a RebuilderInterface or say, in module.json, why it has nothing to rebuild.
 */
final class ModuleRebuildCoverageCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly RebuilderRegistry $rebuilders,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function key(): string
    {
        return 'rebuild';
    }

    public function run(): array
    {
        $classes = [];
        foreach ($this->rebuilders->all() as $rebuilder) {
            $classes[] = $rebuilder::class;
        }

        $findings = [];
        foreach ($this->modules->discoverAllModules() as $module) {
            if ($module['status'] !== 'active') {
                continue;
            }

            $dir = $this->projectDir.'/cp-content/modules/'.$module['dirName'];
            if ((glob($dir.'/Entity/*.php') ?: []) === []) {
                continue;
            }

            if ($this->exemption($dir) !== null || $this->covered((string) $module['dirName'], $classes)) {
                continue;
            }

            $findings[] = new DoctorFinding(
                id: 'rebuild.module_uncovered',
                severity: DoctorFinding::SEVERITY_LOW,
                title: 'Module stores content but cannot be rebuilt',
                detail: \sprintf('%s has entities and registers no rebuilder, so the Rebuild screen cannot recount or re-index its content.', $module['name']),
                remedy: 'Implement App\Core\Rebuild\RebuilderInterface in the module (it is tagged automatically), or add "rebuild": {"exempt": "<why there is nothing derived>"} to module.json.',
            );
        }

        return $findings !== [] ? $findings : [DoctorFinding::pass(
            'rebuild.module_coverage',
            'Module rebuild coverage',
            'Every active module that stores content registers a rebuilder or is marked exempt.',
        )];
    }

    /**
     * @param list<class-string> $rebuilderClasses
     */
    private function covered(string $dirName, array $rebuilderClasses): bool
    {
        foreach ($rebuilderClasses as $class) {
            if (str_starts_with($class, 'Modules\\'.$dirName.'\\')) {
                return true;
            }
        }

        return false;
    }

    private function exemption(string $dir): ?string
    {
        $manifest = json_decode((string) @file_get_contents($dir.'/module.json'), true);
        $reason = \is_array($manifest) ? ($manifest['rebuild']['exempt'] ?? null) : null;

        return \is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
