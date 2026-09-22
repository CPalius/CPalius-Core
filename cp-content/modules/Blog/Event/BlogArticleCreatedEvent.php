<?php

declare(strict_types=1);

namespace Modules\Blog\Event;

use App\Entity\Node;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired after a blog post is created when the author asked for AI auto-translate.
 * Ai listens by NAME; Blog never imports the Ai module.
 */
final class BlogArticleCreatedEvent extends Event
{
    public const NAME = 'blog.article.created';

    public const TRANSLATE = 'blog.article.translate';

    public function __construct(
        private readonly Node $node,
        private readonly bool $autoTranslate,
    ) {
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function shouldTranslate(): bool
    {
        return $this->autoTranslate;
    }
}
