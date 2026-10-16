<?php

declare(strict_types=1);

namespace App\Core\Trash\Task;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Trash\TrashManager;

/**
 * Permanently removes recycle-bin items whose retention window has passed.
 * 05:40 — after the 03:00/04:00/05:12 log purges, for the same reason those are staggered.
 */
final class PurgeTrashTask
{
    public function __construct(
        private readonly TrashManager $trash,
    ) {
    }

    #[CpCronJob(schedule: '40 5 * * *', name: 'trash.purge', description: 'Permanently delete expired recycle-bin items')]
    public function execute(): string
    {
        return \sprintf('Recycle bin: %d expired items removed.', $this->trash->purgeExpired());
    }
}
