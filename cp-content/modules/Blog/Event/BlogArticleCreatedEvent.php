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

    /**
     * Fired after an edit when the author asked to push the change into the
     * AI-written translations of this post (siblings carrying ai_source_id = this node).
     */
    public const SYNC = 'blog.article.translation.sync';

    /** Node data key the Ai module stamps on every translation it writes: the source node id. */
    public const AI_SOURCE_KEY = 'ai_source_id';

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
