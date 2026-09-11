<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Workflow;

use App\Core\Audit\Entity\AuditLog;
use App\Core\Hook\HookContext;
use App\Core\Hook\HookDispatcherInterface;
use App\Core\Resource\ResourceRegistry;
use App\Core\Workflow\Exception\WorkflowException;
use App\Core\Workflow\StateAccessor;
use App\Core\Workflow\WorkflowDefinition;
use App\Core\Workflow\WorkflowManager;
use App\Core\Workflow\WorkflowRegistry;
use App\Core\Workflow\WorkflowTransition;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(WorkflowManager::class)]
#[CoversClass(WorkflowDefinition::class)]
#[CoversClass(StateAccessor::class)]
final class WorkflowManagerTest extends TestCase
{
    private function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            'editorial',
            ['draft', 'review', 'published'],
            [
                'submit' => new WorkflowTransition('submit', ['draft'], 'review', 'content.moderate'),
                'publish' => new WorkflowTransition('publish', ['review'], 'published', 'content.publish'),
            ],
            'draft',
        );
    }

    /**
     * @param list<string> $granted
     */
    private function manager(array $granted, ?EntityManagerInterface $em = null, ?HookDispatcherInterface $hooks = null): WorkflowManager
    {
        $registry = $this->createMock(WorkflowRegistry::class);
        $registry->method('get')->willReturnCallback(fn (string $n): ?WorkflowDefinition => $n === 'editorial' ? $this->definition() : null);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $c): bool => \in_array($c, $granted, true));
        $security->method('getUser')->willReturn(null);

        return new WorkflowManager(
            $registry,
            new StateAccessor(),
            $security,
            $hooks ?? $this->createMock(HookDispatcherInterface::class),
            new ResourceRegistry(),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testApplyWritesStateAndAudits(): void
    {
        $subject = new class {
            private string $status = 'draft';

            public function getStatus(): string
            {
                return $this->status;
            }

            public function setStatus(string $s): void
            {
                $this->status = $s;
            }

            public function getId(): int
            {
                return 7;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(AuditLog::class));

        $hooks = $this->createMock(HookDispatcherInterface::class);
        $hooks->expects(self::once())->method('trigger')
            ->with('workflow.editorial.transitioned', self::isInstanceOf(HookContext::class))
            ->willReturnArgument(1);

        $result = $this->manager(['content.moderate'], $em, $hooks)->apply($subject, 'editorial', 'submit', 'looks good');

        self::assertSame('review', $subject->getStatus());
        self::assertSame(['workflow' => 'editorial', 'transition' => 'submit', 'from' => 'draft', 'to' => 'review'], $result);
    }

    public function testWrongSourcePlaceThrows(): void
    {
        $subject = new class {
            public function getStatus(): string
            {
                return 'draft';
            }

            public function setStatus(string $s): void
            {
            }
        };

        $this->expectException(WorkflowException::class);
        $this->manager(['content.publish'])->apply($subject, 'editorial', 'publish');
    }

    public function testDeniedCapabilityThrows(): void
    {
        $subject = new class {
            public function getStatus(): string
            {
                return 'draft';
            }

            public function setStatus(string $s): void
            {
            }
        };

        $this->expectException(WorkflowException::class);
        $this->manager([])->apply($subject, 'editorial', 'submit');
    }

    public function testEnabledTransitionsRespectPlaceAndCapability(): void
    {
        $subject = new class {
            public function getStatus(): string
            {
                return 'draft';
            }

            public function setStatus(string $s): void
            {
            }
        };

        self::assertSame(['submit'], array_map(
            static fn (WorkflowTransition $t): string => $t->name,
            $this->manager(['content.moderate'])->enabledTransitions($subject, 'editorial'),
        ));
        self::assertSame([], $this->manager([])->enabledTransitions($subject, 'editorial'));
    }

    public function testUnknownWorkflowThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->manager([])->getMarking(new \stdClass(), 'nope');
    }
}
