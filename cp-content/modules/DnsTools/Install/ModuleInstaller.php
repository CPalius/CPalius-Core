<?php

declare(strict_types=1);

namespace Modules\DnsTools\Install;

use App\Core\Module\AbstractSqlModuleInstaller;
use App\Core\Module\ModuleInstallContext;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    private const FALLBACK_LOCALES = ['tr', 'en'];

    protected function moduleId(): string
    {
        return 'dnstools';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }

    public function install(ModuleInstallContext $context): void
    {
        parent::install($context);
        DnsToolsSettingsSeeder::seed($context->connection);
        $this->publishMenuLinks($context);
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        parent::upgrade($context, $fromVersion, $toVersion);
        DnsToolsSettingsSeeder::seed($context->connection);
        $this->publishMenuLinks($context);
    }

    public function uninstall(ModuleInstallContext $context): void
    {
        foreach ($this->activeLocales($context) as $locale) {
            $context->removeMenuLinks('/'.$locale.'/dns-tools');
        }

        parent::uninstall($context);
    }

    private function publishMenuLinks(ModuleInstallContext $context): void
    {
        $labels = [
            'tr' => 'DNS Araçları',
            'en' => 'DNS Tools',
        ];

        foreach ($this->activeLocales($context) as $locale) {
            $context->ensureMenuLink(
                'header',
                $labels[$locale] ?? $labels['en'],
                '/'.$locale.'/dns-tools',
                $locale,
                40,
            );
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
}
