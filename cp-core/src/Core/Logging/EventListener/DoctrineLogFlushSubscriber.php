<?php

declare(strict_types=1);

namespace App\Core\Logging\EventListener;

use App\Core\Logging\Monolog\DoctrineLogHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\ConsoleEvents;

/**
 * Flushes buffered DB log rows after the response / console command finishes.
 */
final class DoctrineLogFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DoctrineLogHandler $handler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onTerminate', -256],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -256],
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->handler->flush();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->handler->flush();
    }
}
