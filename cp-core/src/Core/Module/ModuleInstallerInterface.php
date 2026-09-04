<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Lifecycle hooks a module may implement to manage its own tables and settings.
 * Convention: Modules\<Dir>\Install\ModuleInstaller (see ModuleLifecycleManager).
 */
interface ModuleInstallerInterface
{
    /**
     * Runs once, right after the module is activated for the first time.
     * Must be idempotent: a re-activation calls it again.
     */
    public function install(ModuleInstallContext $context): void;

    /**
     * Runs when the module is deactivated with data removal requested.
     * Must never throw for already-missing tables; deactivation has to stay possible.
     */
    public function uninstall(ModuleInstallContext $context): void;

    /**
     * Runs when an installed module's manifest version changed since the last run.
     */
    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void;
}
