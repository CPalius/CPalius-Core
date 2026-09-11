<?php

declare(strict_types=1);

namespace App\Core\Notification\Hook;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use App\Core\Notification\NotificationDispatcher;
use App\Core\Notification\NotificationSubject;
use App\Entity\Node;
use App\Entity\User;

/**
 * T3.1 proof: editorial moderation transition → author in-app + queued mail.
 */
final class EditorialTransitionNotificationListener
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    #[CpHook(hookPoint: 'workflow.editorial.transitioned')]
    public function onEditorialTransition(HookContext $context): HookContext
    {
        $subject = $context->get('subject');
        if (!$subject instanceof Node) {
            return $context;
        }

        $author = $subject->getAuthor();
        if (!$author instanceof User || $author->getId() === null) {
            return $context;
        }

        $transition = (string) ($context->get('transition') ?? '');
        $from = (string) ($context->get('from') ?? '');
        $to = (string) ($context->get('to') ?? '');
        $nodeId = $subject->getId();

        $this->dispatcher->dispatch(
            'content.workflow.transition',
            $author,
            [
                'node_id' => $nodeId,
                'node_type' => $subject->getType(),
                'title' => $subject->getTitle(),
                'transition' => $transition,
                'from' => $from,
                'to' => $to,
                'comment' => $context->get('comment'),
            ],
            subject: new NotificationSubject('node', $nodeId),
            dedupeKey: $nodeId !== null
                ? sprintf('%d:%s:%s', $nodeId, $transition, $to)
                : null,
        );

        return $context;
    }
}
