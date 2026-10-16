<?php

declare(strict_types=1);

namespace App\Core\Trash;

use App\Core\Trash\Entity\TrashItem;

/**
 * Cleans up what a deleted row left outside the database.
 *
 * The bin keeps the row; anything the row pointed at (an uploaded file, a
 * remote object) has to survive as long as the row can still be restored, and
 * go when the row does. A handler is called just before an item is permanently
 * removed — by hand, by "empty", or by the retention cron — and never on
 * restore.
 *
 * Handlers must not throw: a failed file removal is an orphan to sweep later,
 * not a reason to keep an expired row in the bin forever.
 */
interface TrashPurgeHandlerInterface
{
    public function supports(TrashItem $item): bool;

    public function onPurge(TrashItem $item): void;
}
