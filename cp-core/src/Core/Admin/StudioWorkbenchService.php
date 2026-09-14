<?php

declare(strict_types=1);

namespace App\Core\Admin;

use App\Core\Content\ContentModerationManager;
use App\Core\Security\QueryScopeApplier;
use App\Core\Workflow\WorkflowDefinition;
use App\Core\Workflow\WorkflowRegistry;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * "What is waiting on me" for the Studio desk.
 *
 * The Studio dashboard opened on totals: how much is published, how much is
 * drafted, how many megabytes of media exist. Those are a publisher's
 * questions. An operator of a CRM, an ERP, an HR panel or a ticketing system
 * opens the same screen and needs the one question every application shape
 * shares: which records are sitting in a state that expects a human, and is
 * that human me?
 *
 * CPalius already answers that generically — the workflow engine is a plain
 * state machine over any subject, and content moderation is only its first
 * consumer. This service reads the engine rather than the publication status,
 * so an install whose workflow places are "quote / approved / invoiced" gets
 * exactly the same panel as one whose places are "draft / review / published",
 * without core knowing which of the two it is running.
 */
final class StudioWorkbenchService
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly ContentModerationManager $moderation,
        private readonly WorkflowRegistry $workflows,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{
     *     moderated: bool,
     *     states: list<array{state: string, label: string, count: int, actionable: bool}>,
     *     actionable: int,
     *     mine: array{unfinished: int, authored: int}
     * }
     */
    public function build(): array
    {
        $states = $this->pendingStates();

        $actionable = 0;
        foreach ($states as $state) {
            if ($state['actionable']) {
                $actionable += $state['count'];
            }
        }

        return [
            'moderated' => $states !== [],
            'states' => $states,
            'actionable' => $actionable,
            'mine' => $this->myOpenWork(),
        ];
    }

    /**
     * Counts records parked in each non-final workflow place, and says whether
     * the viewer can move them on.
     *
     * "Actionable" is answered from the transition's capability rather than by
     * asking the guard about one sample record. A sample would be a lie in
     * both directions on an .own/.any split: it would promise action on a
     * hundred records because the viewer happens to own the first one, or hide
     * a whole queue because they do not own it.
     *
     * @return list<array{state: string, label: string, count: int, actionable: bool}>
     */
    private function pendingStates(): array
    {
        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.type AS type, n.moderationState AS state, COUNT(n.id) AS total')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.moderationState IS NOT NULL')
            ->groupBy('n.type')
            ->addGroupBy('n.moderationState');

        // Same access discipline as every other count on this desk: a reviewer
        // must not learn how many records exist behind a door they cannot open.
        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        $counts = [];
        $definitions = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $type = (string) $row['type'];
            $state = (string) $row['state'];

            if ($state === '' || !$this->moderation->isEnabled($type)) {
                continue;
            }

            $definition = $definitions[$type] ??= $this->workflows->get($this->moderation->workflowName($type));
            if (!$definition instanceof WorkflowDefinition || $definition->isPublishPlace($state)) {
                // A record that reached a publish place is finished work, not
                // a queue: listing it would bury the items that need a human.
                continue;
            }

            if (!isset($counts[$state])) {
                $counts[$state] = [
                    'state' => $state,
                    'label' => $definition->placeLabel($state),
                    'count' => 0,
                    'actionable' => $this->canLeave($definition, $state),
                ];
            }

            $counts[$state]['count'] += (int) $row['total'];
        }

        $states = array_values($counts);
        usort($states, static fn (array $a, array $b): int => [$b['actionable'], $b['count']] <=> [$a['actionable'], $a['count']]);

        return $states;
    }

    /**
     * True when at least one transition out of $place is open to the viewer.
     * An uncapabilitied transition is open to everyone who can reach the form.
     */
    private function canLeave(WorkflowDefinition $definition, string $place): bool
    {
        foreach ($definition->transitionsFrom($place) as $transition) {
            if ($transition->capability === null || $this->security->isGranted($transition->capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The viewer's own unfinished work. Unlike the queue above this is not a
     * capability question: these are records the viewer created, so counting
     * them tells them where they left off rather than exposing anything.
     *
     * @return array{unfinished: int, authored: int}
     */
    private function myOpenWork(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ['unfinished' => 0, 'authored' => 0];
        }

        $rows = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS total')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.author = :author')
            ->setParameter('author', $user)
            ->groupBy('n.status')
            ->getQuery()
            ->getArrayResult();

        $authored = 0;
        $unfinished = 0;

        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $authored += $total;

            if ((string) $row['status'] !== Node::STATUS_PUBLISHED) {
                $unfinished += $total;
            }
        }

        return ['unfinished' => $unfinished, 'authored' => $authored];
    }
}
