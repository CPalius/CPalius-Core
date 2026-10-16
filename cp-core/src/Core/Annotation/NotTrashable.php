<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Opt-out: deleting this entity never lands in the recycle bin.
 *
 * For high-churn or derived rows (counters, caches, ledgers) where keeping
 * every deleted row would only grow cp_trash_items. Anything not marked is
 * captured by TrashCaptureListener.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NotTrashable
{
}
