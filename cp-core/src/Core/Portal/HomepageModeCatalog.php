<?php

declare(strict_types=1);

namespace App\Core\Portal;

use App\Core\Module\ModuleContributionCatalog;
use App\Core\Module\ModuleRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Every homepage the installation could serve, including the ones it currently
 * cannot.
 *
 * ModuleContributionCatalog only knows about modules that booted, which is
 * correct for anything that has to run but wrong for a screen whose job is to
 * present a choice. An operator opening the homepage settings saw a dropdown
 * with one entry in it and no way to tell whether that was all the platform
 * offered or the consequence of a module being switched off somewhere else.
 *
 * So this reads the declarations of the installed-but-inactive modules too, off
 * disk, and reports them separately. The screen can then show the full set and
 * say plainly why an option is not selectable — which is a different message
 * from not showing it at all.
 *
 * Only ever read on the settings screen. Everything that resolves the actual
 * homepage at request time goes through ModuleContributionCatalog, where a
 * dormant module correctly does not exist.
 */
final class HomepageModeCatalog
{
    /** The mode core itself provides, which no module can take away. */
    public const CORE_MODE = 'portal';

    public function __construct(
        private readonly ModuleContributionCatalog $contributions,
        private readonly ModuleRegistry $modules,
        private readonly string $modulesDir,
    ) {
    }

    /**
     * Modes that can be selected right now.
     *
     * @return array<string, array{label: string, route: string, module: ?string}>
     */
    public function available(): array
    {
        $owners = $this->declaredByModule();

        // Core's own mode first. It is a compiled #[CpSetting] variant rather
        // than a module contribution, so the contribution catalogue does not
        // know about it — and a list of available homepages that omitted the
        // one the site is probably serving would be a strange list.
        $out = [
            self::CORE_MODE => [
                'label' => 'studio.homepage.mode.portal',
                'route' => 'theme_home_root',
                'module' => null,
            ],
        ];

        foreach ($this->contributions->homepageModes() as $id => $mode) {
            $out[$id] = [
                'label' => $mode['label'],
                'route' => $mode['route'],
                'module' => $owners[$id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Modes that exist on disk but whose module is not active, so the operator
     * can see what activating it would give them.
     *
     * @return array<string, array{label: string, module: string}>
     */
    public function dormant(): array
    {
        $active = $this->contributions->homepageModes();
        $out = [];

        foreach ($this->modules->discoverAllModules() as $module) {
            if ($module['status'] === 'active') {
                continue;
            }

            foreach ($this->declarationsIn($module['dirName']) as $id => $label) {
                if (isset($active[$id]) || isset($out[$id])) {
                    continue;
                }

                $out[$id] = ['label' => $label, 'module' => $module['name']];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Which module each active mode came from, for the label beside it.
     *
     * @return array<string, string> mode id => module name
     */
    private function declaredByModule(): array
    {
        $owners = [];

        foreach ($this->modules->discoverAllModules() as $module) {
            if ($module['status'] !== 'active') {
                continue;
            }

            foreach (array_keys($this->declarationsIn($module['dirName'])) as $id) {
                $owners[$id] = $module['name'];
            }
        }

        return $owners;
    }

    /**
     * The homepage_modes block of one module, read straight from its
     * contributions file.
     *
     * Deliberately forgiving: a module with a broken or missing contributions
     * file simply contributes nothing here. This runs on a settings screen, and
     * a screen that refuses to render because one inactive module has a typo in
     * a file nobody is loading would be the worse failure.
     *
     * @return array<string, string> mode id => label key
     */
    private function declarationsIn(string $dirName): array
    {
        $file = $this->modulesDir.'/'.$dirName.'/Resources/config/contributions.yaml';

        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($data) || !\is_array($data['homepage_modes'] ?? null)) {
            return [];
        }

        $modes = [];

        foreach ($data['homepage_modes'] as $id => $mode) {
            if (!\is_string($id) || $id === '' || !\is_array($mode)) {
                continue;
            }

            $label = $mode['label'] ?? null;
            $route = $mode['route'] ?? null;

            if (\is_string($label) && $label !== '' && \is_string($route) && $route !== '') {
                $modes[$id] = $label;
            }
        }

        return $modes;
    }
}
