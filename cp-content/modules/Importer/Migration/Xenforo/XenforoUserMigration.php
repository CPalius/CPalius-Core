<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Xenforo;

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
 * XenForo members become CPalius users.
 *
 * Runs first in the XenForo chain: threads and posts name their author by
 * user_id, and there is nothing to resolve that against until the users exist.
 *
 * Passwords are not carried over — see UserDestination for why re-hashing a
 * foreign hash is worse than making everyone reset. A member with no address,
 * or one XenForo marked as a spam-cleaned shell, is skipped rather than turned
 * into an account nobody can recover.
 */
final class XenforoUserMigration implements ConfigurableMigrationInterface
{
    public const ID = 'xenforo.users';

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
        return 'XenForo members';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all('xf_'),
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
        $database->assertTables(['user']);

        $user = $database->table('user');
        $profile = $database->table('user_profile');

        return new DatabaseSource(
            $database,
            sprintf('%s u LEFT JOIN %s p ON p.user_id = u.user_id', $user, $profile),
            'u.user_id',
            'u.user_id, u.username, u.email, u.register_date, u.user_state, u.is_staff, p.website, p.signature',
            '',
            500,
            sprintf('XenForo members in %s', $user),
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

        $username = trim($row->getString('username'));
        $signature = $row->getString('signature');

        return $row->withData([
            'email' => $email,
            'username' => $username,
            'importedFrom' => 'xenforo',
            'xenforoUserId' => $row->sourceId,
            'xenforoState' => trim($row->getString('user_state')),
            // Signatures are BBCode like everything else a forum stores.
            'signature' => $signature === '' ? '' : (new BbCodeConverter())->convert($signature),
            'website' => trim($row->getString('website')),
        ]);
    }

    private function database(): ForeignDatabase
    {
        // A caller that already has a connection — a test, or a future runner
        // that opens one connection for the whole board — supplies it here.
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return ForeignDatabase::fromOptions($this->options, 'xf_');
    }
}
