<?php

declare(strict_types=1);

namespace App\Core\Content;

use App\Core\Revision\RevisionManager;
use App\Core\Settings\SettingsRegistry;
use App\Core\Workflow\WorkflowDefinition;
use App\Core\Workflow\WorkflowManager;
use App\Core\Workflow\WorkflowRegistry;
use App\Core\Workflow\WorkflowTransition;
use App\Entity\Node;
use App\Entity\User;

/**
 * Binds a content bundle to an editorial workflow. The moderation place lives on
 * Node::moderation_state; this keeps Node::status (publication) in sync with the
 * workflow's publish places, so existing "status = published" queries just work.
 */
final class ContentModerationManager
{
    public const SETTING_TYPES = 'content.moderation.enabled_types';
    public const SETTING_WORKFLOW = 'content.moderation.workflow';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly WorkflowRegistry $workflows,
        private readonly WorkflowManager $workflowManager,
        private readonly RevisionManager $revisions,
    ) {
    }

    public function isEnabled(string $nodeType): bool
    {
        return \in_array($nodeType, $this->enabledTypes(), true) && $this->definitionFor($nodeType) !== null;
    }

    public function workflowName(string $nodeType): string
    {
        $name = trim((string) $this->settings->get(self::SETTING_WORKFLOW, 'editorial'));

        return $name !== '' ? $name : 'editorial';
    }

    /**
     * Stamp the initial place on a new moderated node. Safe to call repeatedly.
     */
    public function initialize(Node $node): void
    {
        if (!$this->isEnabled($node->getType()) || $node->getModerationState() !== null) {
            return;
        }

        $definition = $this->definitionFor($node->getType());
        if ($definition !== null) {
            $node->setModerationState($definition->initialPlace);
            $this->syncPublication($node, $definition, $definition->initialPlace);
        }
    }

    public function currentState(Node $node): ?string
    {
        if (!$this->isEnabled($node->getType())) {
            return null;
        }

        $definition = $this->definitionFor($node->getType());

        return $definition === null
            ? null
            : ($node->getModerationState() ?? $definition->initialPlace);
    }

    /**
     * @return list<WorkflowTransition> transitions the current user may run now
     */
    public function availableTransitions(Node $node): array
    {
        if (!$this->isEnabled($node->getType())) {
            return [];
        }

        return $this->workflowManager->enabledTransitions($node, $this->workflowName($node->getType()));
    }

    /**
     * @return array{workflow: string, transition: string, from: string, to: string}
     */
    public function apply(Node $node, string $transitionName, ?string $comment = null, ?User $by = null): array
    {
        $workflowName = $this->workflowName($node->getType());
        $result = $this->workflowManager->apply($node, $workflowName, $transitionName, $comment, $by);

        $definition = $this->definitionFor($node->getType());
        if ($definition !== null) {
            $this->syncPublication($node, $definition, $result['to']);
        }

        $this->revisions->stageContext(
            sprintf('Moderation: %s (%s → %s)', $transitionName, $result['from'], $result['to']),
            $by,
        );

        return $result;
    }

    private function syncPublication(Node $node, WorkflowDefinition $definition, string $place): void
    {
        if ($definition->isPublishPlace($place)) {
            if ($node->getStatus() !== Node::STATUS_PUBLISHED) {
                $node->publish();
            }

            return;
        }

        if ($node->getStatus() === Node::STATUS_PUBLISHED) {
            $node->setStatus(Node::STATUS_DRAFT);
        }
    }

    private function definitionFor(string $nodeType): ?WorkflowDefinition
    {
        return $this->workflows->get($this->workflowName($nodeType));
    }

    /**
     * @return list<string>
     */
    private function enabledTypes(): array
    {
        $raw = (string) $this->settings->get(self::SETTING_TYPES, '');

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
    }
}
