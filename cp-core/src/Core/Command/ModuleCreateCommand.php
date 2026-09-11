<?php

declare(strict_types=1);

namespace App\Core\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Scaffolds cp-content/modules/{Name} with module.json, bundle, installer, migrations and translations.
 */
#[AsCommand(
    name: 'cp:module:create',
    description: 'Create a module skeleton under cp-content/modules (manifest, installer, translations).',
)]
final class ModuleCreateCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'PascalCase directory name, e.g. Portfolio');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $name) !== 1) {
            $io->error('Module name must be PascalCase alphanumeric (e.g. Portfolio).');

            return Command::FAILURE;
        }

        $moduleDir = $this->projectDir.'/cp-content/modules/'.$name;

        if (is_dir($moduleDir)) {
            $io->error(sprintf('"%s" already exists.', $moduleDir));

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir([
            $moduleDir,
            $moduleDir.'/Install',
            $moduleDir.'/Resources/migrations',
            $moduleDir.'/Resources/translations',
            $moduleDir.'/Resources/assets',
            $moduleDir.'/Resources/config',
            $moduleDir.'/Controller',
        ]);

        foreach ($this->files($name) as $relative => $contents) {
            $filesystem->dumpFile($moduleDir.'/'.$relative, $contents);
        }

        $io->success(sprintf('Module "%s" created at cp-content/modules/%s', $name, $name));
        $io->text('Activate with: php cp-core/bin/console cp:module:activate '.$name);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function files(string $name): array
    {
        $id = strtolower($name);

        return [
            'module.json' => json_encode([
                'name' => $name,
                'version' => '1.0.0',
                'description' => $name.' module.',
                'author' => 'CPalius',
                'bundle' => 'Modules\\'.$name.'\\'.$name.'Module',
                'core_version' => '^1.0',
                'requires' => new \stdClass(),
                'conflicts' => new \stdClass(),
                'provides' => [$id],
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
            $name.'Module.php' => <<<PHP
<?php

declare(strict_types=1);

namespace Modules\\{$name};

use Symfony\\Component\\HttpKernel\\Bundle\\Bundle;

class {$name}Module extends Bundle
{
}

PHP,
            'Install/ModuleInstaller.php' => <<<PHP
<?php

declare(strict_types=1);

namespace Modules\\{$name}\\Install;

use App\\Core\\Module\\AbstractSqlModuleInstaller;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return '{$id}';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }
}

PHP,
            'Resources/migrations/.gitkeep' => '',
            'Resources/assets/.gitkeep' => '',
            'Resources/config/services.yaml' => <<<YAML
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Modules\\{$name}\\Controller\\:
        resource: '../../Controller/'
        tags: ['controller.service_arguments']

YAML,
            'Resources/config/contributions.yaml' => <<<YAML
# Declared by the module — the core never hardcodes this package.
# Packages that edit cp-core or omit the English catalogue / installer are refused.
# node_show_routes:
#     {$id}: {$id}_show
# schema_types:
#     {$id}: WebPage
# homepage_modes:
#     {$id}:
#         route: {$id}_index
#         label: {$id}.homepage.mode
# queryable_fields:
#     {$id}:
#         is_featured: int
# studio:
#     quick_create: []
#     quick_links: []

YAML,
            'Resources/config/importmap.php' => <<<PHP
<?php

return [
    // '{$id}-admin' => [
    //     'path' => 'Resources/assets/{$id}-admin.js',
    //     'entrypoint' => true,
    // ],
];

PHP,
            'Resources/translations/messages+intl-icu.en.yaml' => "{$id}.test_message: \"{$name} module is speaking English.\"\n",
            'Resources/translations/messages+intl-icu.tr.yaml' => "{$id}.test_message: \"{$name} modülü Türkçe konuşuyor.\"\n",
        ];
    }
}
