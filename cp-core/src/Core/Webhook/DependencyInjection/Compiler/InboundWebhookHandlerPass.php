<?php

declare(strict_types=1);

namespace App\Core\Webhook\DependencyInjection\Compiler;

use App\Core\Webhook\InboundWebhookHandlerInterface;
use App\Core\Webhook\InboundWebhookJobHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Maps inbound webhook endpoint ids to module handlers. Scan errors drop that module only.
 */
final class InboundWebhookHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $map = [];
        $locator = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if (!\is_string($class) || $class === '') {
                continue;
            }
            try {
                if (!is_subclass_of($class, InboundWebhookHandlerInterface::class)) {
                    continue;
                }
                $endpointId = $class::endpointId();
            } catch (\Throwable) {
                continue;
            }
            if (!\is_string($endpointId) || $endpointId === '' || isset($map[$endpointId])) {
                continue;
            }
            $map[$endpointId] = $class;
            $locator[$class] = new Reference($id);
        }

        if ($container->hasDefinition(InboundWebhookJobHandler::class)) {
            $container->getDefinition(InboundWebhookJobHandler::class)
                ->setArgument('$handlerLocator', ServiceLocatorTagPass::register($container, $locator))
                ->setArgument('$handlerMap', $map);
        }
    }
}
