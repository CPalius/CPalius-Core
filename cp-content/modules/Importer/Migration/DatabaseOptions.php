<?php

declare(strict_types=1);

namespace Modules\Importer\Migration;

use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\DBAL\Connection;
use Modules\Importer\Source\SqlDumpLoader;

/**
 * The fields every database-backed import asks for.
 *
 * Declared once because they must be identical across every migration in a
 * system: the import screen merges the options of all of them into one form,
 * and a driver that called its prefix field something else would make the
 * operator fill the same value in twice.
 *
 * Two ways in: a live (or already-imported) MySQL, or a .sql dump uploaded
 * into the form. The dump path is the small-board convenience; a 2 GB forum
 * still belongs on the remote fields so PHP is not the pipe.
 */
final class DatabaseOptions
{
    /**
     * @return list<MigrationOption>
     */
    public static function all(string $defaultPrefix): array
    {
        return [
            MigrationOption::optional(
                'sqlDump',
                'Uploaded .sql / .sql.gz dump of the old database. For small boards; leave empty to connect remotely instead.',
                null,
                MigrationOption::KIND_FILE,
            ),
            MigrationOption::optional('dbHost', 'Source database host, optionally host:port — used when no dump is uploaded'),
            MigrationOption::optional('dbName', 'Source database name — used when no dump is uploaded'),
            MigrationOption::optional('dbUser', 'Database user — read-only is enough, and safer'),
            MigrationOption::secret('dbPassword', 'Database password', false),
            MigrationOption::optional('prefix', 'Table prefix used by the source installation', $defaultPrefix),
            MigrationOption::directory(
                'data',
                'Old board files: XenForo data/ (and internal_data/ if you have it), MyBB uploads/, or the board root that contains them. Zip and upload, or point at a path. Avatars and post images come from here.',
                false,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['sqlDump', 'dbHost', 'dbName', 'dbUser', 'dbPassword', 'prefix', 'data'];
    }

    /**
     * @param array<string, string> $options
     */
    public static function connect(array $options, string $defaultPrefix = '', ?Connection $app = null): ForeignDatabase
    {
        $dump = trim($options['sqlDump'] ?? '');
        $prefix = trim($options['prefix'] ?? $defaultPrefix);

        if ($dump !== '') {
            if ($app === null) {
                throw new \LogicException('A SQL dump was given but the application database connection is not available.');
            }

            return (new SqlDumpLoader($app))->open($dump, $prefix);
        }

        if (trim($options['dbName'] ?? '') === '') {
            throw new \RuntimeException('Either upload a .sql dump of the old database, or fill in the remote host, database name and user.');
        }

        return ForeignDatabase::fromOptions($options + ['prefix' => $prefix], $defaultPrefix);
    }
}
