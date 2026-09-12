<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Joomla;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\UserDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * Joomla users become CPalius users.
 *
 * Joomla's table prefix is randomised at install time — "jos_" on old sites,
 * something like "x7k2p_" on anything recent — so unlike the forum packages
 * there is no sensible default and the operator has to read it out of their
 * configuration.php. The field says so.
 *
 * A blocked Joomla account is imported blocked: the old site had a reason, and
 * a migration is not the place to overturn a ban.
 */
final class JoomlaUserMigration implements ConfigurableMigrationInterface
{
    public const ID = 'joomla.users';

    /** @var array<string, string> */
    private readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        array $options = [],
        private readonly ?ForeignDatabase $suppliedDatabase = null,
    ) {
        $this->options = $options;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Joomla users';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all(''),
            MigrationOption::optional('role', 'CPalius role for imported accounts', 'member'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['users']);

        $users = $database->table('users');

        return new DatabaseSource(
            $database,
            $users.' u',
            'u.id',
            'u.id, u.name, u.username, u.email, u.registerDate, u.block',
            '',
            500,
            sprintf('Joomla users in %s', $users),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new UserDestination(
            $this->entityManager,
            [$this->options['role'] ?? 'member'],
        );
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $email = trim($row->getString('email'));

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $displayName = trim($row->getString('name'));
        $login = trim($row->getString('username'));

        return $row->withData([
            'email' => $email,
            'username' => $displayName !== '' ? $displayName : $login,
            'importedFrom' => 'joomla',
            'joomlaUserId' => $row->sourceId,
            'joomlaLogin' => $login,
            'joomlaBlocked' => trim($row->getString('block')) === '1',
        ]);
    }

    private function database(): ForeignDatabase
    {
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return ForeignDatabase::fromOptions($this->options);
    }
}
