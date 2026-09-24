<?php

declare(strict_types=1);

namespace App\Core\Module\DependencyInjection\Compiler;

use App\Core\Module\ModuleEventListenerGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rewrites every Modules\*-owned kernel.event_listener/kernel.event_subscriber
 * tag to run through ModuleEventListenerGuard instead of the module's class
 * directly (Law 2.1/2.3, extended). HookManager already isolates the blessed
 * #[CpHook] point; this closes the other door — a module wiring itself
 * straight onto Symfony's own dispatcher (EventSubscriberInterface,
 * #[AsEventListener]) is just as capable of turning one broken listener into
 * a site-wide 500 on kernel.request/controller/exception, and that path was
 * previously untouched by any isolation at all.
 *
 * Runs at the default TYPE_BEFORE_OPTIMIZATION stage, which is guaranteed to
 * execute before FrameworkBundle's RegisterListenersPass (registered at
 * TYPE_BEFORE_REMOVING) actually turns these tags into dispatcher
 * registrations — so by the time that pass runs, the original tags are gone
 * and only the guard's are left.
 *
 * App\* (core) listeners are never touched: a core listener throwing is a
 * real core bug that must propagate, not something to silently paper over —
 * exactly the boundary ModuleIsolationTest already proves for boot failures.
 */
final class ModuleEventListenerGuardPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(LoggerInterface::class) && !$container->hasAlias(LoggerInterface::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds('kernel.event_subscriber', true) as $id => $_tags) {
            $this->guardSubscriber($container, $id);
        }

        foreach ($container->findTaggedServiceIds('kernel.event_listener', true) as $id => $tags) {
            $this->guardListener($container, $id, $tags);
        }
    }

    private function guardSubscriber(ContainerBuilder $container, string $id): void
    {
        $definition = $container->getDefinition($id);
        $class = $definition->getClass();

        if ($class === null || !$this->isModuleOwned($class) || !is_a($class, EventSubscriberInterface::class, true)) {
            return;
        }

        foreach ($this->normalizeSubscribedEvents($class::getSubscribedEvents()) as [$eventName, $method, $priority]) {
            $this->registerGuard($container, $id, $class, $method, $eventName, $priority);
        }

        $definition->clearTag('kernel.event_subscriber');
    }

    /**
     * @param list<array{event?: string, method?: string, priority?: int}> $tags
     */
    private function guardListener(ContainerBuilder $container, string $id, array $tags): void
    {
        $definition = $container->getDefinition($id);
        $class = $definition->getClass();

        // Already handled as a subscriber, or the subscriber guard already
        // cleared this service's kernel.event_subscriber tag above — either
        // way, an explicit kernel.event_listener tag on the same service is
        // independent and still needs its own guard.
        if ($class === null || !$this->isModuleOwned($class)) {
            return;
        }

        foreach ($tags as $attributes) {
            $eventName = $attributes['event'] ?? null;

            // The rare form that infers the event from a typed parameter
            // (no explicit "event" key) needs the same reflection RegisterListenersPass
            // does internally; left unguarded rather than reimplemented here —
            // no shipped module currently uses it (verified before adding this pass).
            if ($eventName === null) {
                continue;
            }

            $method = $attributes['method'] ?? '__invoke';
            $priority = (int) ($attributes['priority'] ?? 0);

            $this->registerGuard($container, $id, $class, $method, $eventName, $priority);
        }

        $definition->clearTag('kernel.event_listener');
    }

    private function registerGuard(
        ContainerBuilder $container,
        string $innerServiceId,
        string $innerClass,
        string $method,
        string $eventName,
        int $priority,
    ): void {
        $guardId = sprintf(
            'cpalius.module_listener_guard.%s',
            substr(hash('xxh128', $innerServiceId.'::'.$method.'@'.$eventName), 0, 16),
        );

        $guard = new Definition(ModuleEventListenerGuard::class, [
            new Reference($innerServiceId),
            $method,
            $eventName,
            $innerClass.'::'.$method,
            new Reference(LoggerInterface::class),
            '%kernel.project_dir%',
        ]);
        $guard->addTag('kernel.event_listener', ['event' => $eventName, 'priority' => $priority]);
        $guard->setPublic(false);

        $container->setDefinition($guardId, $guard);
    }

    private function isModuleOwned(string $class): bool
    {
        return str_starts_with($class, self::MODULE_NAMESPACE_PREFIX);
    }

    /**
     * Normalizes the three shapes EventSubscriberInterface::getSubscribedEvents()
     * is allowed to return into flat [event, method, priority] triples.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function normalizeSubscribedEvents(array $subscribed): array
    {
        $normalized = [];

        foreach ($subscribed as $eventName => $params) {
            if (\is_string($params)) {
                $normalized[] = [$eventName, $params, 0];

                continue;
            }

            if (\is_array($params) && $params !== [] && \is_string($params[0] ?? null)) {
                // [method, priority] — a single pair, not a list of pairs.
                $normalized[] = [$eventName, $params[0], (int) ($params[1] ?? 0)];

                continue;
            }

            if (\is_array($params)) {
                foreach ($params as $pair) {
                    if (\is_array($pair) && \is_string($pair[0] ?? null)) {
                        $normalized[] = [$eventName, $pair[0], (int) ($pair[1] ?? 0)];
                    }
                }
            }
        }

        return $normalized;
    }
}
