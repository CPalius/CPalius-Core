<?php

declare(strict_types=1);

namespace Modules\Forum\Trash;

use App\Core\Trash\SoftDeleteHandlerInterface;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Service\ForumTopicService;

/**
 * Brings a deleted topic back through the forum's own path, so section counters, the discussion
 * state and the public cache are put right — clearing deletedAt alone would leave them wrong.
 */
final class ForumTopicSoftDeleteHandler implements SoftDeleteHandlerInterface
{
    public function __construct(private readonly ForumTopicService $topics)
    {
    }

    public function supports(string $class): bool
    {
        return is_a($class, ForumTopic::class, true);
    }

    public function restore(object $entity): void
    {
        \assert($entity instanceof ForumTopic);
        $entity->setDeletedAt(null);
        $this->topics->restoreTopic($entity);
    }
}
