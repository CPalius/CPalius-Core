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
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\WxrAuthorSource;
use Modules\Importer\Source\Wordpress\WxrReader;

/**
 * WordPress authors become CPalius users.
 *
 * Runs before everything else in the WordPress chain: a post carries its author
 * as a login string, and there is nothing to resolve that against until the
 * authors are in the map.
 *
 * An author with no email address is skipped rather than invented for. The
 * account would be unrecoverable — no password came across, and password reset
 * is the only way in — so it would be an account nobody can ever use, occupying
 * the name of someone who might later register properly.
 */
final class WordpressAuthorMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.authors';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $file = '',
        private readonly string $role = 'member',
    ) {
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
            MigrationOption::required('file', 'Path to the WordPress WXR export file'),
            MigrationOption::optional('role', 'CPalius role for imported accounts', 'member'),
        ];
    }

    public function withOptions(array $values): static
    {
        $resolved = MigrationOptionResolver::resolve($this->options(), $values);

        return new static($this->entityManager, $resolved['file'], $resolved['role']);
    }

    public function source(): MigrationSourceInterface
    {
        return new WxrAuthorSource($this->reader());
    }

    public function destination(): MigrationDestinationInterface
    {
        return new UserDestination($this->entityManager, [$this->role]);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $email = trim($row->getString('email'));

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

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
        ]);
    }

    private function reader(): WxrReader
    {
        if ($this->file === '') {
            throw new \LogicException('This migration has not been configured; pass -o file=<export.xml>.');
        }

        return new WxrReader($this->file);
    }
}
