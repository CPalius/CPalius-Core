<?php

declare(strict_types=1);

namespace Modules\Messages\Install;

use App\Core\Module\AbstractSqlModuleInstaller;
use App\Core\Module\ModuleInstallContext;
use Symfony\Component\Yaml\Yaml;

/**
 * Install / upgrade / uninstall hooks. Instantiated with `new`, never through
 * the container — an inactive module has no services yet.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    private const FALLBACK_LOCALES = ['tr', 'en'];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_GRANTS = [
        'member' => [
            'messages.send',
            'messages.report',
            'messages.block',
        ],
        'editor' => [
            'messages.send',
            'messages.report',
            'messages.block',
            'messages.moderate',
            'messages.report.moderate',
            'messages.settings.manage',
            'messages.quota.exempt',
        ],
    ];

    protected function moduleId(): string
    {
        return 'messages';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_message_reports',
            'cp_message_restrictions',
            'cp_message_blocks',
            'cp_messages',
            'cp_message_participants',
            'cp_message_threads',
        ];
    }

    public function install(ModuleInstallContext $context): void
    {
        parent::install($context);
        $this->grantRoleCapabilities($context);
        $this->retractMenuLinks($context);
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        parent::upgrade($context, $fromVersion, $toVersion);
        $this->grantRoleCapabilities($context);
        $this->retractMenuLinks($context);
    }

    public function uninstall(ModuleInstallContext $context): void
    {
        $this->retractMenuLinks($context);
        $this->revokeRoleCapabilities($context);
        parent::uninstall($context);
    }

    /**
     * Navigation is the operator's to compose. An earlier installer published
     * header/footer links on activate; those are retracted here and never added
     * again.
     */
    private function retractMenuLinks(ModuleInstallContext $context): void
    {
        foreach ($this->activeLocales($context) as $locale) {
            $context->removeMenuLinks('/'.$locale.'/messages');
        }
    }

    /**
     * @return list<string>
     */
    private function activeLocales(ModuleInstallContext $context): array
    {
        try {
            /** @var list<string> $codes */
            $codes = $context->connection->fetchFirstColumn(
                'SELECT code FROM cp_locales WHERE is_active = 1 ORDER BY sort_order ASC',
            );
            $codes = array_values(array_filter($codes, static fn (mixed $c): bool => \is_string($c) && $c !== ''));

            return $codes !== [] ? $codes : self::FALLBACK_LOCALES;
        } catch (\Throwable) {
            return self::FALLBACK_LOCALES;
        }
    }

    private function grantRoleCapabilities(ModuleInstallContext $context): void
    {
        foreach (self::ROLE_GRANTS as $roleId => $capabilities) {
            $path = $this->roleFilePath($context, $roleId);
            $existing = $this->readRoleCapabilities($path);
            if ($existing === null) {
                continue;
            }

            $missing = array_values(array_diff($capabilities, $existing));
            if ($missing !== []) {
                $this->appendRoleCapabilities($path, $missing);
            }
        }
    }

    private function revokeRoleCapabilities(ModuleInstallContext $context): void
    {
        foreach (self::ROLE_GRANTS as $roleId => $capabilities) {
            $path = $this->roleFilePath($context, $roleId);
            if ($this->readRoleCapabilities($path) === null) {
                continue;
            }

            $this->removeRoleCapabilityLines($path, $capabilities);
        }
    }

    private function roleFilePath(ModuleInstallContext $context, string $roleId): string
    {
        return $context->projectDir.'/cp-content/config/sync/user.role.'.$roleId.'.yaml';
    }

    /**
     * @return list<string>|null
     */
    private function readRoleCapabilities(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($data) || !\is_array($data['role'] ?? null) || !\is_array($data['role']['capabilities'] ?? null)) {
            return null;
        }

        $existing = array_values(array_filter($data['role']['capabilities'], 'is_string'));

        return \in_array('*', $existing, true) ? null : $existing;
    }

    /**
     * @param list<string> $capabilities
     */
    private function appendRoleCapabilities(string $path, array $capabilities): void
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $lastIndex = null;
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            if (trim($lines[$i]) !== '') {
                $lastIndex = $i;
                break;
            }
        }

        if ($lastIndex === null || preg_match('/^(\s+)-\s+\S/', $lines[$lastIndex], $match) !== 1) {
            return;
        }

        $indent = $match[1];
        $appended = array_slice($lines, 0, $lastIndex + 1);
        $appended[] = $indent.'# Added by the Messages module installer.';
        foreach ($capabilities as $capability) {
            $appended[] = $indent.'- '.$capability;
        }

        @file_put_contents($path, implode("\n", $appended)."\n", \LOCK_EX);
    }

    /**
     * @param list<string> $capabilities
     */
    private function removeRoleCapabilityLines(string $path, array $capabilities): void
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $kept = [];
        $changed = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '# Added by the Messages module installer.') {
                $changed = true;
                continue;
            }

            if (preg_match('/^-\s*[\'"]?([a-z0-9_.]+)[\'"]?$/', $trimmed, $match) === 1
                && \in_array($match[1], $capabilities, true)
            ) {
                $changed = true;
                continue;
            }

            $kept[] = $line;
        }

        if ($changed) {
            @file_put_contents($path, implode("\n", $kept), \LOCK_EX);
        }
    }
}
