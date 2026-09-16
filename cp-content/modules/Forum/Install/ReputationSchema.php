<?php

declare(strict_types=1);

namespace Modules\Forum\Install;

use Doctrine\DBAL\Connection;

/**
 * Adds cp_forum_user_reputations.topic_url when it is not there yet.
 *
 * The module's own SQL migration does this too, but that only runs when the
 * module is upgraded — and this feature ships as a file patch, which replaces
 * files and runs nothing. Without this guard the reputation form would offer a
 * link field and every submission would fail on an unknown column until an
 * operator found AACP → Updates and pressed Apply.
 *
 * Checked through information_schema rather than "ADD COLUMN IF NOT EXISTS":
 * MariaDB understands that clause and MySQL 8 does not, and this module runs on
 * both. Runs at most once per request, and only in a request that actually
 * gives reputation.
 */
final class ReputationSchema
{
    private bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Never throws. A database user without ALTER rights leaves the column
     * missing; giving reputation then fails the way it would have before, and
     * the module upgrade still fixes it properly later.
     */
    public function ensure(): bool
    {
        if ($this->ensured) {
            return true;
        }

        $this->ensured = true;

        try {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['cp_forum_user_reputations', 'topic_url'],
            );

            if ($exists > 0) {
                return true;
            }

            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_user_reputations ADD COLUMN topic_url VARCHAR(500) DEFAULT NULL',
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
