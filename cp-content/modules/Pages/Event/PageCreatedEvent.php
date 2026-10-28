<?php

declare(strict_types=1);

namespace Modules\Pages\Event;

use App\Entity\Node;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired after a page is created or an editor asks for AI auto-translate.
 * Ai listens by NAME; Pages never imports the Ai module.
 */
final class PageCreatedEvent extends Event
{
    public const NAME = 'page.created';

    public const TRANSLATE = 'page.translate';

    /**
     * Fired after an edit when the editor asked to push the change into the AI-written translations
     * of this page (siblings carrying ai_source_id = this node).
     */
    public const SYNC = 'page.translation.sync';

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
