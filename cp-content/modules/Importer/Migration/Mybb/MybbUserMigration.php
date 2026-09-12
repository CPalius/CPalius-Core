<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Mybb;

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
use Modules\Importer\Markup\BbCodeConverter;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * MyBB members become CPalius users.
 *
 * MyBB stores its salted-MD5 hashes in the same table; they are not carried
 * over, for the reason UserDestination sets out — a weak primitive kept alive
 * behind a strong-looking wrapper is worse than asking everyone to reset.
 */
final class MybbUserMigration implements ConfigurableMigrationInterface
{
    public const ID = 'mybb.users';

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
        return 'MyBB members';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all('mybb_'),
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
            'u.uid',
            'u.uid, u.username, u.email, u.regdate, u.postnum, u.signature, u.website',
            '',
            500,
            sprintf('MyBB members in %s', $users),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new UserDestination($this->entityManager, [$this->options['role'] ?? 'member']);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $email = trim($row->getString('email'));

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $signature = $row->getString('signature');

        return $row->withData([
            'email' => $email,
            'username' => trim($row->getString('username')),
            'importedFrom' => 'mybb',
            'mybbUserId' => $row->sourceId,
            'signature' => $signature === '' ? '' : (new BbCodeConverter())->convert($signature),
            'website' => trim($row->getString('website')),
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

        return ForeignDatabase::fromOptions($this->options, 'mybb_');
    }
}
