<?php

declare(strict_types=1);

namespace Modules\Showcase\Install;

use App\Core\Module\AbstractSqlModuleInstaller;
use App\Core\Module\ModuleInstallContext;
use Symfony\Component\Yaml\Yaml;

/**
 * Install / upgrade / uninstall hooks for the Showcase module.
 *
 * ModuleLifecycleManager instantiates installers with `new`, never through the
 * container — an inactive module has no services yet. That is why this class
 * takes no constructor arguments and talks to the database through the DBAL
 * connection on the context instead of injecting repositories.
 *
 * Everything here is idempotent: re-activating a module runs install() again.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    private const DEFAULT_TYPE = 'product';

    /** Fallback when cp_locales cannot be read (fresh install, mid-migration). */
    private const FALLBACK_LOCALES = ['tr', 'en'];

    /**
     * @var array<string, array{label: string, description: string}>
     */
    private const DEFAULT_TYPE_LABELS = [
        'tr' => ['label' => 'Ürün', 'description' => 'Genel amaçlı vitrin kaydı. Alanlarını bu türe istediğiniz gibi ekleyin.'],
        'en' => ['label' => 'Product', 'description' => 'General-purpose showcase entry. Add whatever fields this type needs.'],
    ];

    /**
     * Capabilities granted to the stock roles so the module works the moment it
     * is activated. Without this an operator would activate the module, see an
     * empty submit page, and have to hand-edit YAML to find out why.
     *
     * Removed again on uninstall — exactly these lines, nothing else.
     *
     * @var array<string, list<string>> role id => capabilities
     */
    private const ROLE_GRANTS = [
        'member' => [
            'showcase.item.create',
            'showcase.item.view.own',
            'showcase.item.edit.own',
            'showcase.item.delete.own',
            'showcase.review.create',
        ],
        'editor' => [
            'showcase.item.create',
            'showcase.item.view.own',
            'showcase.item.view.any',
            'showcase.item.edit.own',
            'showcase.item.edit.any',
            'showcase.item.delete.own',
            'showcase.item.delete.any',
            'showcase.item.publish',
            'showcase.item.moderate',
            'showcase.review.create',
            'showcase.review.moderate',
        ],
    ];

    protected function moduleId(): string
    {
        return 'showcase';
    }

    /**
     * Children first: the index, media, link, review and term rows all point at
     * cp_showcase_items, and the items point at cp_showcase_types.
     *
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_showcase_item_index',
            'cp_showcase_item_media',
            'cp_showcase_item_terms',
            'cp_showcase_links',
            'cp_showcase_reviews',
            'cp_showcase_items',
            'cp_showcase_type_translations',
            'cp_showcase_types',
        ];
    }

    public function install(ModuleInstallContext $context): void
    {
        parent::install($context);

        $this->seedDefaultType($context);
        $this->grantRoleCapabilities($context);
        $this->retractMenuLinks($context);
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        parent::upgrade($context, $fromVersion, $toVersion);

        $this->seedDefaultType($context);
        $this->grantRoleCapabilities($context);
        $this->retractMenuLinks($context);
    }

    public function uninstall(ModuleInstallContext $context): void
    {
        $this->retractMenuLinks($context);

        // Field definitions live in the core cp_field_definitions table, keyed by
        // a "showcase_*" bundle. They belong to this module's types, so purging
        // data has to take them too — otherwise a reinstall would resurrect field
        // definitions for types that no longer exist.
        $this->deleteShowcaseFieldDefinitions($context);
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
            $context->removeMenuLinks('/'.$locale.'/showcase');
        }
    }

    /**
     * Creates one general-purpose type so an operator has somewhere to publish
     * immediately. Additional types (and their field schemas) are created from
     * the admin screen, including one-click presets.
     */
    private function seedDefaultType(ModuleInstallContext $context): void
    {
        $connection = $context->connection;

        try {
            $existing = $connection->fetchOne(
                'SELECT id FROM cp_showcase_types WHERE machine_name = :name LIMIT 1',
                ['name' => self::DEFAULT_TYPE],
            );

            if ($existing !== false && $existing !== null) {
                return;
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $vocabulary = 'showcase_'.self::DEFAULT_TYPE;

            $settings = json_encode([
                'features' => [
                    'price' => true,
                    'gallery' => true,
                    'external_url' => true,
                    'demo_url' => true,
                    'contact' => true,
                    'location' => false,
                    'body' => true,
                    'reviews' => true,
                    'links' => true,
                    'categories' => true,
                ],
            ], \JSON_THROW_ON_ERROR);

            $connection->executeStatement(
                'INSERT INTO cp_showcase_types (machine_name, icon, vocabulary, weight, enabled, settings, created_at, updated_at)
                 VALUES (:name, :icon, :vocabulary, 0, 1, :settings, :now, :now)',
                [
                    'name' => self::DEFAULT_TYPE,
                    'icon' => 'heroicons:cube',
                    'vocabulary' => $vocabulary,
                    'settings' => $settings,
                    'now' => $now,
                ],
            );

            $typeId = (int) $connection->lastInsertId();

            if ($typeId <= 0) {
                return;
            }

            foreach ($this->activeLocales($context) as $locale) {
                $texts = self::DEFAULT_TYPE_LABELS[$locale] ?? self::DEFAULT_TYPE_LABELS['en'];

                $connection->executeStatement(
                    'INSERT INTO cp_showcase_type_translations (type_id, locale, label, description)
                     VALUES (:typeId, :locale, :label, :description)',
                    [
                        'typeId' => $typeId,
                        'locale' => $locale,
                        'label' => $texts['label'],
                        'description' => $texts['description'],
                    ],
                );
            }

            $this->seedVocabulary($context, $vocabulary, self::DEFAULT_TYPE_LABELS['en']['label']);
        } catch (\Throwable) {
            // A missing starter type is a cosmetic loss; the operator creates one
            // from the admin screen. Activation must not fail over it.
        }
    }

    private function seedVocabulary(ModuleInstallContext $context, string $machineName, string $label): void
    {
        $connection = $context->connection;

        try {
            $existing = $connection->fetchOne(
                'SELECT id FROM cp_vocabularies WHERE machine_name = :name LIMIT 1',
                ['name' => $machineName],
            );

            if ($existing !== false && $existing !== null) {
                return;
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $connection->executeStatement(
                'INSERT INTO cp_vocabularies (machine_name, label, description, hierarchical, weight, created_at, updated_at)
                 VALUES (:name, :label, NULL, 1, 50, :now, :now)',
                ['name' => $machineName, 'label' => $label, 'now' => $now],
            );
        } catch (\Throwable) {
            // Categories are optional per type; the admin screen can create the
            // vocabulary later through VocabularySeeder.
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

    /**
     * Removes the field definitions belonging to this module's types.
     *
     * The bundles are read back from cp_showcase_types and deleted by exact
     * value rather than matched with a LIKE pattern: "showcase_%" would also
     * catch a bundle called "showcases", and an uninstall is the worst possible
     * moment to delete a row that belongs to somebody else.
     */
    private function deleteShowcaseFieldDefinitions(ModuleInstallContext $context): void
    {
        try {
            /** @var list<string> $machineNames */
            $machineNames = $context->connection->fetchFirstColumn('SELECT machine_name FROM cp_showcase_types');

            $bundles = array_values(array_map(
                static fn (string $name): string => 'showcase_'.$name,
                array_filter($machineNames, static fn (mixed $n): bool => \is_string($n) && $n !== ''),
            ));

            if ($bundles === []) {
                return;
            }

            foreach ($bundles as $bundle) {
                $context->connection->executeStatement(
                    'DELETE FROM cp_field_definitions WHERE bundle = :bundle',
                    ['bundle' => $bundle],
                );
            }
        } catch (\Throwable) {
            // Uninstall must never block deactivation.
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
     * Current capabilities of a role, or null when the file must not be touched
     * (missing, unreadable, not a role file, or a "*" wildcard role that already
     * holds everything).
     *
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
     * Appends capability lines to a role file instead of re-dumping it.
     *
     * These files are versioned config a human maintains, and they carry comments
     * explaining each grant. Yaml::dump() would round-trip the data correctly and
     * throw every one of those comments away, so the lines are appended textually
     * — and only when the file genuinely ends with the capability list, which is
     * verified before anything is written.
     *
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

        // Only safe when the list is the last thing in the file; otherwise the
        // appended lines would land in the wrong YAML block.
        if ($lastIndex === null || preg_match('/^(\s+)-\s+\S/', $lines[$lastIndex], $match) !== 1) {
            return;
        }

        $indent = $match[1];
        $appended = array_slice($lines, 0, $lastIndex + 1);
        $appended[] = $indent.'# Added by the Showcase module installer.';

        foreach ($capabilities as $capability) {
            $appended[] = $indent.'- '.$capability;
        }

        @file_put_contents($path, implode("\n", $appended)."\n", \LOCK_EX);
    }

    /**
     * Drops exactly the lines this installer added, leaving every other line —
     * including comments and capabilities from other modules — untouched.
     *
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

            if ($trimmed === '# Added by the Showcase module installer.') {
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
