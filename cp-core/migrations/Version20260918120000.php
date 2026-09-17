<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records what each forum visitor is: member, guest, spider or bot.
 *
 * "Who is online: 84" is a number nobody can act on, because most of it is
 * usually automated traffic. The kind is stored rather than derived because the
 * User-Agent only exists on the request that wrote the row, and the panel that
 * shows the breakdown runs long after those requests are gone.
 *
 * @see \Modules\Forum\Install\ForumPresenceKindSchema the runtime guard that
 *      adds the same column on installations that take this as a file patch
 */
final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cp_forum_presence.kind (member/guest/spider/bot) for the who-is-online breakdown.';
    }

    /**
     * MySQL commits implicitly on DDL, which invalidates the savepoint the
     * migration runner opened around this migration; releasing it afterwards
     * then fails even though the column was added and the version recorded.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cp_forum_presence'
               AND COLUMN_NAME = 'kind'",
        );

        if ($exists > 0) {
            // The runtime guard got here first on a site that took the patch.
            return;
        }

        $this->connection->executeStatement(
            "ALTER TABLE cp_forum_presence
             ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'guest',
             ADD INDEX idx_forum_presence_kind (kind)",
        );

        // Rows written before this release carry no kind. A signed-in row is a
        // member; the rest stay 'guest', which is what they were counted as
        // anyway, and they age out of the online window within minutes.
        $this->connection->executeStatement(
            "UPDATE cp_forum_presence SET kind = 'member' WHERE user_id IS NOT NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE cp_forum_presence DROP COLUMN kind');
    }
}
