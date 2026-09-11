<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Reads and writes a workflow's place on the subject via a normal getter/setter
 * (e.g. getStatus()/setStatus()). Subjects don't need a special interface.
 */
final class StateAccessor
{
    private readonly PropertyAccessorInterface $accessor;

    public function __construct(?PropertyAccessorInterface $accessor = null)
    {
        $this->accessor = $accessor ?? PropertyAccess::createPropertyAccessor();
    }

    public function read(object $subject, WorkflowDefinition $definition): string
    {
        try {
            $value = $this->accessor->getValue($subject, $definition->subjectProperty);
        } catch (\Throwable) {
            return $definition->initialPlace;
        }

        $value = \is_string($value) ? $value : '';

        return $definition->hasPlace($value) ? $value : $definition->initialPlace;
    }

    public function write(object $subject, WorkflowDefinition $definition, string $place): void
    {
        if (!$this->accessor->isWritable($subject, $definition->subjectProperty)) {
            throw new \LogicException(sprintf(
                'Workflow "%s" subject %s has no writable "%s" property.',
                $definition->name,
                $subject::class,
                $definition->subjectProperty,
            ));
        }

        $this->accessor->setValue($subject, $definition->subjectProperty, $place);
    }
}
