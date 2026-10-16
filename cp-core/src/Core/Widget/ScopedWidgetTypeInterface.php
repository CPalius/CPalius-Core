<?php

declare(strict_types=1);

namespace App\Core\Widget;

/**
 * A widget type that belongs to certain modules (a forum "latest topics" box has no business in the docs).
 *
 * Implementing this is optional: a plain WidgetTypeInterface is a general widget and can go anywhere. Scoped
 * types are offered only in the areas of the modules they name, plus any area an admin explicitly adds them to.
 */
interface ScopedWidgetTypeInterface extends WidgetTypeInterface
{
    /**
     * @return list<string> module dir names ("Forum", "Docs"…) whose areas this widget is meant for
     */
    public function modules(): array;
}