<?php

declare(strict_types=1);

namespace App\Core\Module;

use Psr\Log\LoggerInterface;

/**
 * Stands in for a module's raw Symfony event listener/subscriber method so a
 * throw in it does not take the whole request down.
 *
 * HookManager already isolates the blessed #[CpHook] extension point (Law
 * 2.1/2.3), but a module is free to register an ordinary
 * EventSubscriberInterface/#[AsEventListener] on core kernel events
 * (kernel.request, kernel.controller, kernel.exception, ...) — those run on
 * every request through Symfony's own dispatcher, completely outside
 * HookManager, so an uncaught exception there used to 500 every page,
 * including AACP. ModuleEventListenerGuardPass rewrites every such
 * module-owned listener to run through an instance of this class instead.
 *
 * One instance wraps exactly one (service, method, event) triple — the
 * compiler pass creates one per listener entry rather than reusing a shared
 * instance, so a failure is attributable to a single, named source.
 */
final class ModuleEventListenerGuard
{
    public function __construct(
        private readonly object $inner,
        private readonly string $method,
        private readonly string $eventName,
        private readonly string $sourceLabel,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(object $event): void
    {
        try {
            $this->inner->{$this->method}($event);
        } catch (\Throwable $e) {
            $this->quarantine($e);
        }
    }

    private function quarantine(\Throwable $e): void
    {
        $this->logger->warning('Module event listener failed during execution and was skipped.', [
            'source' => $this->sourceLabel,
            'event' => $this->eventName,
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] Kernel event "%s": %s skipped at runtime because it failed. Reason: %s',
            date('Y-m-d H:i:s'),
            $this->eventName,
            $this->sourceLabel,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.\PHP_EOL, \FILE_APPEND | \LOCK_EX);
    }
}
