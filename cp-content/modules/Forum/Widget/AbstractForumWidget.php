<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

use App\Core\Widget\ScopedWidgetTypeInterface;

/** Shared identity of the forum's widget types: ids "forum.*", offered in forum areas only. */
abstract class AbstractForumWidget implements ScopedWidgetTypeInterface
{
    abstract protected function name(): string;

    final public function id(): string
    {
        return 'forum.'.$this->name();
    }

    final public function label(): string
    {
        return 'forum.widget.type.'.$this->name().'.label';
    }

    final public function description(): string
    {
        return 'forum.widget.type.'.$this->name().'.description';
    }

    final public function group(): string
    {
        return 'widget.group.forum';
    }

    /** @return list<string> */
    final public function modules(): array
    {
        return ['Forum'];
    }
}
