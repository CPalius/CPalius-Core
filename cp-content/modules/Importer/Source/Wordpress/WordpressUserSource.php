<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;

/**
 * wp_users, keyed by login so a post's post_author resolves the same way
 * WXR's dc:creator does.
 */
final class WordpressUserSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly ForeignDatabase $database,
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress users in %s', $this->database->table('users'));
    }

    public function rows(): iterable
    {
        $users = $this->database->table('users');
        $meta = $this->database->table('usermeta');
        $hasMeta = $this->database->hasTable('usermeta');

        $from = $hasMeta
            ? sprintf(
                '%s u
                 LEFT JOIN %s fn ON fn.user_id = u.ID AND fn.meta_key = \'first_name\'
                 LEFT JOIN %s ln ON ln.user_id = u.ID AND ln.meta_key = \'last_name\'',
                $users,
                $meta,
                $meta,
            )
            : $users.' u';

        $select = $hasMeta
            ? 'u.ID, u.user_login, u.user_email, u.display_name, fn.meta_value AS first_name, ln.meta_value AS last_name'
            : 'u.ID, u.user_login, u.user_email, u.display_name';

        $inner = new DatabaseSource(
            $this->database,
            $from,
            'u.ID',
            $select,
            '',
            500,
            $this->describe(),
        );

        foreach ($inner->rows() as $row) {
            $login = trim($row->getString('user_login'));

            if ($login === '') {
                continue;
            }

            yield new MigrationRow($login, [
                'login' => $login,
                'wpId' => $row->getString('ID'),
                'email' => $row->getString('user_email'),
                'displayName' => $row->getString('display_name'),
                'firstName' => $row->getString('first_name'),
                'lastName' => $row->getString('last_name'),
            ]);
        }
    }

    public function count(): ?int
    {
        try {
            return (int) $this->database->connection()->fetchOne(
                'SELECT COUNT(*) FROM '.$this->database->table('users'),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
