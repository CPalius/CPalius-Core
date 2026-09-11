<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds entity classes Doctrine was never told about.
 *
 * CPalius keeps each core slice's entities in its own Entity/ directory rather
 * than one src/Entity tree, and every one of those directories needs an
 * explicit mapping entry in doctrine.yaml. Forgetting one produces code that
 * looks complete — the entity, the repository, the migration and the screens
 * all exist — and fails only at the moment something touches it, with
 * "Could not find the entity manager for class X".
 *
 * That is not hypothetical: Notification (T3.1), LogEntry and MailLog (T3.5)
 * all shipped this way. Notifications broke the theme header, and /aacp/logs
 * and the mail log were unreachable. Nothing caught it, because the ordinary
 * guards cannot: doctrine:schema:validate only inspects entities it already
 * knows about, and no test touched them.
 *
 * The check compares what is on disk against what the metadata driver
 * actually loaded, so it reports precisely the classes that fell through.
 */
final class EntityMappingCoverageCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function key(): string
    {
        return 'entity-mapping';
    }

    public function run(): array
    {
        $onDisk = $this->entityClassesOnDisk();

        if ($onDisk === []) {
            return [DoctorFinding::pass(
                'entity-mapping.unmapped',
                'Entity mapping',
                'No entity classes were found to check.',
            )];
        }

        $mapped = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $mapped[$metadata->getName()] = true;
        }

        $unmapped = array_values(array_filter(
            $onDisk,
            static fn (string $class): bool => !isset($mapped[$class]),
        ));

        if ($unmapped === []) {
            return [DoctorFinding::pass(
                'entity-mapping.unmapped',
                'Entity mapping',
                sprintf('All %d entity classes are covered by a Doctrine mapping.', \count($onDisk)),
            )];
        }

        return [new DoctorFinding(
            id: 'entity-mapping.unmapped',
            // High: the code compiles, the screens exist, and the failure only
            // appears when a visitor reaches the feature.
            severity: DoctorFinding::SEVERITY_HIGH,
            title: 'Entity classes are not covered by any Doctrine mapping',
            detail: implode(', ', $unmapped),
            remedy: 'Add a mappings entry for the containing directory in cp-core/config/packages/doctrine.yaml.',
        )];
    }

    /**
     * Every class carrying #[ORM\Entity], found by reading the source rather
     * than by asking Doctrine — asking Doctrine is exactly what cannot reveal
     * the ones it never loaded.
     *
     * @return list<class-string>
     */
    private function entityClassesOnDisk(): array
    {
        $classes = [];

        foreach ($this->entityDirectories() as $namespacePrefix => $directory) {
            foreach (glob($directory.'/*.php') ?: [] as $file) {
                $source = @file_get_contents($file);

                if (!\is_string($source) || !str_contains($source, '#[ORM\Entity')) {
                    continue;
                }

                /** @var class-string $class */
                $class = $namespacePrefix.'\\'.basename($file, '.php');

                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return array<string, string> namespace prefix => absolute directory
     */
    private function entityDirectories(): array
    {
        $directories = ['App\\Entity' => $this->projectDir.'/cp-core/src/Entity'];

        // Core slices: cp-core/src/Core/<Slice>/Entity → App\Core\<Slice>\Entity
        foreach (glob($this->projectDir.'/cp-core/src/Core/*/Entity', \GLOB_ONLYDIR) ?: [] as $directory) {
            $slice = basename(\dirname($directory));
            $directories['App\\Core\\'.$slice.'\\Entity'] = $directory;
        }

        return array_filter($directories, 'is_dir');
    }
}
