<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Wordpress;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\UserDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Migration\ImportedAccountEmail;
use Modules\Importer\Source\Wordpress\WordpressOrigin;

/**
 * WordPress authors become CPalius users.
 *
 * Runs before everything else in the WordPress chain: a post carries its author
 * as a login string, and there is nothing to resolve that against until the
 * authors are in the map.
 *
 * An author with no usable address still becomes an account (placeholder
 * @invalid.invalid) so their posts keep a profile. They cannot reset a
 * password until an admin gives them a real address.
 */
final class WordpressAuthorMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.authors';

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
        return 'WordPress authors';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...WordpressOrigin::commonOptions(),
            MigrationOption::optional('role', 'CPalius role for imported accounts', 'member'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        return $this->origin()->authors();
    }

    public function destination(): MigrationDestinationInterface
    {
        return new UserDestination($this->entityManager, [$this->options['role'] ?? 'member']);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $email = ImportedAccountEmail::resolve($row->getString('email'), 'wordpress', $row->sourceId);
        $display = trim($row->getString('displayName'));
        $login = trim($row->getString('login'));

        return $row->withData([
            'email' => $email,
            'username' => $display !== '' ? $display : $login,
            'firstName' => trim($row->getString('firstName')),
            'lastName' => trim($row->getString('lastName')),
            'importedFrom' => 'wordpress',
            'wordpressLogin' => $login,
            'wordpressId' => trim($row->getString('wpId')),
            'emailPlaceholder' => ImportedAccountEmail::isPlaceholder($email) ? '1' : '',
        ]);
    }

    private function origin(): WordpressOrigin
    {
        return new WordpressOrigin($this->entityManager, $this->options, $this->suppliedDatabase);
    }
}
