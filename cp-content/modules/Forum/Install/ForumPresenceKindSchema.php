<?php

declare(strict_types=1);

namespace Modules\Forum\Install;

use Doctrine\DBAL\Connection;

/**
 * Adds cp_forum_presence.kind when it is not there yet.
 *
 * The who-is-online breakdown needs to know what each visitor was — member,
 * guest, spider or bot — and that is a new column. A patch cannot run a
 * migration, so the column has to be able to appear on its own; the migration
 * still ships for fresh installs and whichever runs second finds it there.
 *
 * MySQL has no `ADD COLUMN IF NOT EXISTS`, so existence is checked against
 * information_schema first. Same shape as MailTemplateSchema otherwise.
 */
final class ForumPresenceKindSchema
{
    private bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns true when the column exists afterwards. Never throws: without it
     * the board falls back to counting members and guests only, which is what
     * it did before this feature.
     */
    public function ensure(): bool
    {
        if ($this->ensured) {
            return true;
        }

        // Marked done up front: a database that refuses DDL must not be asked
        // again on every single request for the rest of the day.
        $this->ensured = true;

        try {
            $exists = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'cp_forum_presence'
                   AND COLUMN_NAME = 'kind'",
            );

            if ($exists > 0) {
                return true;
            }

            $this->connection->executeStatement(
                "ALTER TABLE cp_forum_presence
                 ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'guest',
                 ADD INDEX idx_forum_presence_kind (kind)",
            );

            // Rows written before this release have no kind. A signed-in row is
            // a member; the rest stay 'guest', which is what they were counted
            // as anyway, and they age out of the online window within minutes.
            $this->connection->executeStatement(
                "UPDATE cp_forum_presence SET kind = 'member' WHERE user_id IS NOT NULL",
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
