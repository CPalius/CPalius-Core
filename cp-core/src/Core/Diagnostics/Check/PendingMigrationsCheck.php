<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reports migrations that exist on disk but have not been executed.
 *
 * This check exists because of a real incident: five migrations sat unapplied
 * across several development sessions. Nothing broke loudly — the features
 * that needed the new tables were written to degrade quietly (session
 * recording and password history are best-effort by design), so the only
 * symptom was security telemetry silently writing nowhere. An unapplied
 * migration is therefore rated HIGH, not medium: the danger is not that it
 * fails, it is that it does not.
 *
 * The reverse case is rated too. A migration recorded in the database but
 * missing from disk usually means a branch switch or a partial deploy, and it
 * makes the schema unreproducible.
 */
final class PendingMigrationsCheck implements DoctorCheckInterface
{
    public function __construct(
        // The bundle registers this service but no autowiring alias for the
        // class, so the id is named explicitly rather than added to
        // services.yaml.
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
    ) {
    }

    public function key(): string
    {
        return 'migrations';
    }

    public function run(): array
    {
        // Everything here can fail when the database is unreachable, which is
        // itself a finding rather than a crash: cp:doctor must stay usable on
        // an installation whose database is down.
        try {
            $available = $this->migrations->getMigrationRepository()->getMigrations();
            $executed = $this->migrations->getMetadataStorage()->getExecutedMigrations();
        } catch (\Throwable $e) {
            return [new DoctorFinding(
                id: 'migrations.unreadable',
                severity: DoctorFinding::SEVERITY_HIGH,
                title: 'Migration status could not be read',
                detail: sprintf('%s: %s', $e::class, $e->getMessage()),
                remedy: 'Check DATABASE_URL and that the database server is running.',
            )];
        }

        $pending = [];
        foreach ($available->getItems() as $migration) {
            if (!$executed->hasMigration($migration->getVersion())) {
                $pending[] = $this->label($migration->getVersion());
            }
        }

        $orphaned = [];
        foreach ($executed->getItems() as $executedMigration) {
            if (!$available->hasMigration($executedMigration->getVersion())) {
                $orphaned[] = $this->label($executedMigration->getVersion());
            }
        }

        $findings = [];

        if ($pending !== []) {
            $findings[] = new DoctorFinding(
                id: 'migrations.pending',
                severity: DoctorFinding::SEVERITY_HIGH,
                title: 'Migrations are waiting to be applied',
                detail: sprintf(
                    '%d pending: %s',
                    \count($pending),
                    implode(', ', $this->shorten($pending)),
                ),
                remedy: 'php cp-core/bin/console doctrine:migrations:migrate',
            );
        }

        if ($orphaned !== []) {
            $findings[] = new DoctorFinding(
                id: 'migrations.orphaned',
                severity: DoctorFinding::SEVERITY_MEDIUM,
                title: 'Executed migrations are missing from disk',
                detail: sprintf(
                    '%d recorded but not found: %s',
                    \count($orphaned),
                    implode(', ', $this->shorten($orphaned)),
                ),
                remedy: 'A branch switch or partial deploy usually causes this; the schema is no longer reproducible from cp-core/migrations.',
            );
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::pass(
                'migrations.pending',
                'Migrations',
                sprintf('All %d migrations are applied.', \count($available->getItems())),
            );
        }

        return $findings;
    }

    /**
     * Version::__toString() returns the fully qualified class name; the
     * namespace is identical for every migration and only costs console width.
     */
    private function label(object $version): string
    {
        $version = (string) $version;
        $position = strrpos($version, '\\');

        return $position === false ? $version : substr($version, $position + 1);
    }

    /**
     * Keeps the console line readable when a fresh installation has dozens of
     * pending migrations; the count above is the number that matters.
     *
     * @param list<string> $versions
     *
     * @return list<string>
     */
    private function shorten(array $versions): array
    {
        if (\count($versions) <= 5) {
            return $versions;
        }

        $head = \array_slice($versions, 0, 5);
        $head[] = sprintf('… and %d more', \count($versions) - 5);

        return $head;
    }
}
