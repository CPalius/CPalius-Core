<?php

declare(strict_types=1);

namespace App\Core\Revision\Cron;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Revision\Repository\NodeRevisionRepository;
use App\Core\Revision\RevisionManager;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Safety net for the per-node revision cap — mainly for when the limit is lowered.
 * The listener already prunes on every save.
 */
final class PruneNodeRevisionsTask
{
    public function __construct(
        private readonly NodeRevisionRepository $repository,
        private readonly RevisionManager $revisions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[CpCronJob(schedule: '30 3 * * *', name: 'content.revisions.prune', description: 'Trim node revision history to the configured per-node limit')]
    public function execute(): string
    {
        $keep = $this->revisions->maxPerNode();
        $removed = 0;

        foreach ($this->repository->nodeIdsOverLimit($keep) as $nodeId) {
            $node = $this->entityManager->find(Node::class, $nodeId);
            if ($node instanceof Node) {
                $removed += $this->repository->pruneNode($node, $keep);
            }
        }

        return sprintf('pruned=%d keep=%d', $removed, $keep);
    }
}
