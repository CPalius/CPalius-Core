<?php

declare(strict_types=1);

namespace App\Core\Module;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Runs install/uninstall/upgrade hooks and tracks the installed version per module.
 * State lives in cp_settings so it survives cache clears and needs no extra table.
 */
final class ModuleLifecycleManager
{
    /** Convention: Modules\<Dir>\Install\ModuleInstaller. */
    private const INSTALLER_CLASS_TEMPLATE = 'Modules\\%s\\Install\\ModuleInstaller';

    private const STATE_MODULE = 'module_lifecycle';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $modulesDir,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Called after a module becomes active. Installs on first activation,
     * upgrades when the manifest version moved on, and is a no-op otherwise.
     *
     * @return array{ran: 'install'|'upgrade'|'none', message: ?string}
     */
    public function onActivated(ModuleManifest $manifest): array
    {
        $installer = $this->resolveInstaller($manifest);
        $installedVersion = $this->installedVersion($manifest->dirName);

        if ($installer === null) {
            $this->rememberVersion($manifest->dirName, $manifest->version);

            return ['ran' => 'none', 'message' => null];
        }

        $context = $this->buildContext($manifest);

        try {
            if ($installedVersion === null) {
                $installer->install($context);
                $this->rememberVersion($manifest->dirName, $manifest->version);

                return ['ran' => 'install', 'message' => null];
            }

            if (version_compare($installedVersion, $manifest->version, '<')) {
                $installer->upgrade($context, $installedVersion, $manifest->version);
                $this->rememberVersion($manifest->dirName, $manifest->version);

                return ['ran' => 'upgrade', 'message' => null];
            }
        } catch (Throwable $e) {
            return ['ran' => 'none', 'message' => $e->getMessage()];
        }

        return ['ran' => 'none', 'message' => null];
    }

    /**
     * Called when a module is deactivated WITH data removal requested.
     * Plain deactivation never touches data; the caller decides.
     *
     * @return array{ran: bool, message: ?string}
     */
    public function onUninstalled(ModuleManifest $manifest): array
    {
        $installer = $this->resolveInstaller($manifest);

        if ($installer === null) {
            $this->forgetVersion($manifest->dirName);

            return ['ran' => false, 'message' => null];
        }

        try {
            $installer->uninstall($this->buildContext($manifest));
        } catch (Throwable $e) {
            return ['ran' => false, 'message' => $e->getMessage()];
        }

        $this->forgetVersion($manifest->dirName);

        return ['ran' => true, 'message' => null];
    }

    public function hasInstaller(ModuleManifest $manifest): bool
    {
        return $this->resolveInstaller($manifest) !== null;
    }

    public function installedVersion(string $dirName): ?string
    {
        try {
            $value = $this->connection->fetchOne(
                'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $this->stateKey($dirName)],
            );
        } catch (Throwable) {
            return null;
        }

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Installers are instantiated with `new`, not through the container: an inactive
     * module's services are not registered, so DI is unavailable at this point.
     */
    private function resolveInstaller(ModuleManifest $manifest): ?ModuleInstallerInterface
    {
        $class = sprintf(self::INSTALLER_CLASS_TEMPLATE, $manifest->dirName);

        try {
            if (!class_exists($class) || !is_subclass_of($class, ModuleInstallerInterface::class)) {
                return null;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            return $reflection->newInstance();
        } catch (Throwable) {
            return null;
        }
    }

    private function buildContext(ModuleManifest $manifest): ModuleInstallContext
    {
        return new ModuleInstallContext(
            connection: $this->connection,
            manifest: $manifest,
            moduleDir: $this->modulesDir.'/'.$manifest->dirName,
            projectDir: $this->projectDir,
        );
    }

    private function rememberVersion(string $dirName, string $version): void
    {
        try {
            $key = $this->stateKey($dirName);
            $exists = $this->connection->fetchOne('SELECT id FROM cp_settings WHERE setting_key = :key LIMIT 1', ['key' => $key]);

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            if ($exists === false || $exists === null) {
                $this->connection->executeStatement(
                    'INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (:key, :value, :module, :now)',
                    ['key' => $key, 'value' => $version, 'module' => self::STATE_MODULE, 'now' => $now],
                );

                return;
            }

            $this->connection->executeStatement(
                'UPDATE cp_settings SET setting_value = :value, updated_at = :now WHERE setting_key = :key',
                ['key' => $key, 'value' => $version, 'now' => $now],
            );
        } catch (Throwable) {
            // Losing the marker only means install() runs again; it is idempotent.
        }
    }

    private function forgetVersion(string $dirName): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM cp_settings WHERE setting_key = :key',
                ['key' => $this->stateKey($dirName)],
            );
        } catch (Throwable) {
            // Non-fatal, see rememberVersion().
        }
    }

    private function stateKey(string $dirName): string
    {
        return 'module_lifecycle.'.strtolower($dirName).'.installed_version';
    }
}
