<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * The authors a WordPress export declares at channel level.
 *
 * Keyed by author_login rather than author_id, because the login is what every
 * <item> carries in dc:creator — keying on the numeric id would mean resolving
 * a post's author through a second lookup that the export does not support.
 */
final class WxrAuthorSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly WxrReader $reader,
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress authors in %s', $this->reader->path());
    }

    public function rows(): iterable
    {
        foreach ($this->reader->authors() as $author) {
            $login = trim($author['author_login'] ?? '');

            if ($login === '') {
                continue;
            }

            yield new MigrationRow($login, [
                'login' => $login,
                'wpId' => $author['author_id'] ?? '',
                'email' => $author['author_email'] ?? '',
                'displayName' => $author['author_display_name'] ?? '',
                'firstName' => $author['author_first_name'] ?? '',
                'lastName' => $author['author_last_name'] ?? '',
            ]);
        }
    }

    public function count(): int
    {
        // Authors sit in the channel header, so counting them does not mean
        // reading the (potentially enormous) body of the export.
        return iterator_count($this->reader->authors());
    }
}
