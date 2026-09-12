<?php

declare(strict_types=1);

namespace Modules\Importer\Migration;

use App\Core\Migrate\MigrationOption;

/**
 * The five fields every database-backed import asks for.
 *
 * Declared once because they must be identical across every migration in a
 * system: the import screen merges the options of all of them into one form,
 * and a driver that called its prefix field something else would make the
 * operator fill the same value in twice.
 */
final class DatabaseOptions
{
    /**
     * @return list<MigrationOption>
     */
    public static function all(string $defaultPrefix): array
    {
        return [
            MigrationOption::required('dbHost', 'Source database host, optionally host:port'),
            MigrationOption::required('dbName', 'Source database name'),
            MigrationOption::required('dbUser', 'Database user — read-only is enough, and safer'),
            MigrationOption::secret('dbPassword', 'Database password', false),
            MigrationOption::optional('prefix', 'Table prefix used by the source installation', $defaultPrefix),
        ];
    }

    /**
     * The option names above, for handing a migration only what it declares.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['dbHost', 'dbName', 'dbUser', 'dbPassword', 'prefix'];
    }
}
