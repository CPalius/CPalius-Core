<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use App\Core\Audit\Entity\AuditLog;
use App\Core\Hook\HookContext;
use App\Core\Hook\HookDispatcherInterface;
use App\Core\Resource\ResourceRegistry;
use App\Core\Workflow\Exception\WorkflowException;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Runs workflow transitions: source-place check, per-transition capability,
 * state write, audit entry, and a hook so modules can react
 * (workflow.{name}.transitioned).
 *
 * apply() does NOT flush — the caller owns the unit of work.
 */
class WorkflowManager
{
    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly StateAccessor $state,
        private readonly Security $security,
        private readonly HookDispatcherInterface $hooks,
        private readonly ResourceRegistry $resources,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getMarking(object $subject, string $workflowName): string
    {
        return $this->state->read($subject, $this->definition($workflowName));
    }

    /**
     * @return list<WorkflowTransition> transitions available from the current place, capability included
     */
    public function enabledTransitions(object $subject, string $workflowName): array
    {
        $definition = $this->definition($workflowName);
        $place = $this->state->read($subject, $definition);

        return array_values(array_filter(
            $definition->transitionsFrom($place),
            fn (WorkflowTransition $t): bool => $this->capabilityGranted($t),
        ));
    }

    public function can(object $subject, string $workflowName, string $transitionName): bool
    {
        $definition = $this->definition($workflowName);
        $transition = $definition->getTransition($transitionName);
        if ($transition === null) {
            return false;
        }

        return $transition->acceptsFrom($this->state->read($subject, $definition))
            && $this->capabilityGranted($transition);
    }

    /**
     * @return array{workflow: string, transition: string, from: string, to: string}
     *
     * @throws WorkflowException
     */
    public function apply(object $subject, string $workflowName, string $transitionName, ?string $comment = null, ?User $by = null): array
    {
        $definition = $this->definition($workflowName);

        $transition = $definition->getTransition($transitionName);
        if ($transition === null) {
            throw WorkflowException::unknownTransition($workflowName, $transitionName);
        }

        $from = $this->state->read($subject, $definition);
        if (!$transition->acceptsFrom($from)) {
            throw WorkflowException::notApplicable($workflowName, $transitionName, $from);
        }
        if (!$this->capabilityGranted($transition)) {
            throw WorkflowException::denied($workflowName, $transitionName);
        }

        $this->state->write($subject, $definition, $transition->to);

        $result = ['workflow' => $workflowName, 'transition' => $transitionName, 'from' => $from, 'to' => $transition->to];

        if ($definition->auditable) {
            $this->audit($subject, $result, $comment, $by);
        }

        $this->hooks->trigger('workflow.'.$workflowName.'.transitioned', new HookContext([
            'subject' => $subject,
            'workflow' => $workflowName,
            'transition' => $transitionName,
            'from' => $from,
            'to' => $transition->to,
            'comment' => $comment,
        ]));

        return $result;
    }

    private function definition(string $name): WorkflowDefinition
    {
        return $this->registry->get($name) ?? throw WorkflowException::unknownWorkflow($name);
    }

    private function capabilityGranted(WorkflowTransition $transition): bool
    {
        return $transition->capability === null || $this->security->isGranted($transition->capability);
    }

    /**
     * @param array{workflow: string, transition: string, from: string, to: string} $result
     */
    private function audit(object $subject, array $result, ?string $comment, ?User $by): void
    {
        $resourceName = $this->resources->get($subject::class)?->name;
        if ($resourceName === null || $resourceName === '') {
            $short = (new \ReflectionClass($subject))->getShortName();
            $resourceName = strtolower($short);
        }

        $resourceId = method_exists($subject, 'getId') ? (string) $subject->getId() : null;

        $log = new AuditLog(
            $resourceName,
            $resourceId,
            'workflow.transition',
            [
                $result['workflow'] => [$result['from'], $result['to']],
                'transition' => [null, $result['transition']],
                'comment' => [null, $comment],
            ],
            $by?->getId() ?? ($this->security->getUser() instanceof User ? $this->security->getUser()->getId() : null),
        );

        $this->entityManager->persist($log);
    }
}
