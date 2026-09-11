<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Entity;

use App\Core\Entity\Event\EntityLifecycleRejectedException;
use App\Core\Entity\Event\EntityPostDeleteEvent;
use App\Core\Entity\Event\EntityPostInsertEvent;
use App\Core\Entity\Event\EntityPostUpdateEvent;
use App\Core\Entity\Event\EntityPreDeleteEvent;
use App\Core\Entity\Event\EntityPreSaveEvent;
use App\Core\Entity\EventListener\EntityLifecycleListener;
use App\Core\Hook\HookContext;
use App\Core\Hook\HookDispatcherInterface;
use App\Entity\Node;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityLifecycleListener::class)]
final class EntityLifecycleListenerTest extends TestCase
{
    private function em(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    public function testPrePersistFiresTypedEventOnPerTypeAndWildcardHookPoints(): void
    {
        $dispatcher = new RecordingHookDispatcher();
        $listener = new EntityLifecycleListener($dispatcher);

        $node = new Node('T', 't', 'page', 'en');
        $listener->prePersist(new PrePersistEventArgs($node, $this->em()));

        self::assertSame(['entity.node.pre_save', 'entity.any.pre_save'], $dispatcher->hookPoints());

        $event = $dispatcher->eventFor('entity.node.pre_save');
        self::assertInstanceOf(EntityPreSaveEvent::class, $event);
        self::assertSame($node, $event->getEntity());
        self::assertSame('node', $event->getEntityTypeId());
        self::assertTrue($event->isNew());
    }

    public function testPreUpdateMarksEventAsNotNew(): void
    {
        $dispatcher = new RecordingHookDispatcher();
        $listener = new EntityLifecycleListener($dispatcher);

        $node = new Node('T', 't', 'page', 'en');
        $changeSet = [];
        $listener->preUpdate(new PreUpdateEventArgs($node, $this->em(), $changeSet));

        /** @var EntityPreSaveEvent $event */
        $event = $dispatcher->eventFor('entity.node.pre_save');
        self::assertFalse($event->isNew());
    }

    public function testPreSaveRejectionAbortsWithTheReasonKey(): void
    {
        $dispatcher = new RecordingHookDispatcher();
        $dispatcher->on('entity.node.pre_save', static function (HookContext $context): void {
            $context->get('event')->reject('node.validation.title_too_short');
        });
        $listener = new EntityLifecycleListener($dispatcher);

        $node = new Node('T', 't', 'page', 'en');

        try {
            $listener->prePersist(new PrePersistEventArgs($node, $this->em()));
            self::fail('Expected EntityLifecycleRejectedException.');
        } catch (EntityLifecycleRejectedException $e) {
            self::assertSame('node.validation.title_too_short', $e->getReasonKey());
        }

        // Both the per-type and wildcard hook points still fire — the reject() flag
        // on the shared event object is only read AFTER both have run.
        self::assertSame(['entity.node.pre_save', 'entity.any.pre_save'], $dispatcher->hookPoints());
    }

    public function testPostInsertUpdateDeleteFireTheRightEventClasses(): void
    {
        $dispatcher = new RecordingHookDispatcher();
        $listener = new EntityLifecycleListener($dispatcher);
        $node = new Node('T', 't', 'page', 'en');

        $listener->postPersist(new PostPersistEventArgs($node, $this->em()));
        self::assertInstanceOf(EntityPostInsertEvent::class, $dispatcher->eventFor('entity.node.post_insert'));

        $listener->postUpdate(new PostUpdateEventArgs($node, $this->em()));
        self::assertInstanceOf(EntityPostUpdateEvent::class, $dispatcher->eventFor('entity.node.post_update'));

        $listener->preRemove(new PreRemoveEventArgs($node, $this->em()));
        self::assertInstanceOf(EntityPreDeleteEvent::class, $dispatcher->eventFor('entity.node.pre_delete'));

        $listener->postRemove(new PostRemoveEventArgs($node, $this->em()));
        self::assertInstanceOf(EntityPostDeleteEvent::class, $dispatcher->eventFor('entity.node.post_delete'));
    }

    public function testPreDeleteRejectionAborts(): void
    {
        $dispatcher = new RecordingHookDispatcher();
        $dispatcher->on('entity.node.pre_delete', static function (HookContext $context): void {
            $context->get('event')->reject('node.delete.has_children');
        });
        $listener = new EntityLifecycleListener($dispatcher);

        $this->expectException(EntityLifecycleRejectedException::class);
        $listener->preRemove(new PreRemoveEventArgs(new Node('T', 't', 'page', 'en'), $this->em()));
    }

    public function testNonFieldableEntityIsIgnored(): void
    {
        $dispatcher = new RecordingHookDispatcher();
        $listener = new EntityLifecycleListener($dispatcher);

        $listener->prePersist(new PrePersistEventArgs(new Setting('x.y'), $this->em()));

        self::assertSame([], $dispatcher->hookPoints(), 'a non-fieldable entity must not trigger any entity.* hook');
    }
}

/**
 * Test double recording every trigger() call; optional per-hook-point callback
 * lets a test simulate a listener's own reaction (reject/allow/deny).
 */
final class RecordingHookDispatcher implements HookDispatcherInterface
{
    /** @var list<array{0: string, 1: HookContext}> */
    private array $calls = [];

    /** @var array<string, callable(HookContext): void> */
    private array $handlers = [];

    public function on(string $hookPoint, callable $handler): void
    {
        $this->handlers[$hookPoint] = $handler;
    }

    public function trigger(string $hookPoint, HookContext $context): HookContext
    {
        $this->calls[] = [$hookPoint, $context];

        $handler = $this->handlers[$hookPoint] ?? null;
        if ($handler !== null) {
            $handler($context);
        }

        return $context;
    }

    /**
     * @return list<string>
     */
    public function hookPoints(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }

    public function eventFor(string $hookPoint): mixed
    {
        foreach ($this->calls as [$point, $context]) {
            if ($point === $hookPoint) {
                return $context->get('event');
            }
        }

        return null;
    }
}
