<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetTypeInterface;

/**
 * Shared by the blog's widget types: one catalogue section, and "<id>" doubling as the translation prefix
 * ("blog.widget.type.<name>.label" / ".description"), so a type only declares its id, icon, fields and body.
 */
abstract class AbstractBlogWidget implements WidgetTypeInterface
{
    /** The part of the id after "blog.". */
    abstract protected function name(): string;

    final public function id(): string
    {
        return 'blog.'.$this->name();
    }

    final public function label(): string
    {
        return 'blog.widget.type.'.$this->name().'.label';
    }

    final public function description(): string
    {
        return 'blog.widget.type.'.$this->name().'.description';
    }

    final public function group(): string
    {
        return 'widget.group.blog';
    }
}
