<?php

declare(strict_types=1);

namespace App\Core\PathAlias;

use App\Core\Entity\Event\AbstractEntityLifecycleEvent;
use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * T2.4: bound to the T1.5 entity events (same wiring as T2.3's EntityCacheTagInvalidator)
 * instead of a raw #[AsDoctrineListener] — a bug in pattern/token resolution is quarantined
 * by the Hook engine instead of aborting the node save (Core Never Dies extends here too).
 *
 * Flushing its own new UrlAlias row from inside a post_insert/post_update hook mirrors the
 * project's existing NodeRevisionListener (same "flush its own rows, never touch the
 * committed Node" convention); the re-entrancy guard is defensive — UrlAlias is not
 * FieldableInterface, so EntityLifecycleListener never re-dispatches for it anyway.
 */
final class PathAliasAutoGenerateListener
{
    private bool $generating = false;

    public function __construct(
        private readonly PathAliasGenerator $generator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[CpHook(hookPoint: 'entity.node.post_insert')]
    #[CpHook(hookPoint: 'entity.node.post_update')]
    public function onNodeSaved(HookContext $context): void
    {
        if ($this->generating) {
            return;
        }

        $event = $context->get('event');
        if (!$event instanceof AbstractEntityLifecycleEvent) {
            return;
        }

        $node = $event->getEntity();
        if (!$node instanceof Node) {
            return;
        }

        $this->generating = true;
        try {
            if ($this->generator->generateForNode($node) !== null) {
                $this->entityManager->flush();
            }
        } finally {
            $this->generating = false;
        }
    }
}
