<?php

declare(strict_types=1);

namespace App\Core\Webhook;

use App\Core\Queue\Entity\AsyncJob;
use App\Core\Queue\AsyncJobHandlerInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Dispatches verified inbound payloads to the contributing module handler in isolation.
 */
final class InboundWebhookJobHandler implements AsyncJobHandlerInterface
{
    /**
     * @param array<string, string> $handlerMap endpointId => serviceId
     */
    public function __construct(
        private readonly ContainerInterface $handlerLocator,
        private readonly array $handlerMap,
        private readonly string $projectDir,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === AsyncJob::TYPE_INBOUND_WEBHOOK;
    }

    public function handle(AsyncJob $job): void
    {
        $payload = $job->getPayload();
        $endpointId = (string) ($payload['endpoint_id'] ?? '');
        $body = $payload['body'] ?? [];
        if ($endpointId === '' || !\is_array($body)) {
            throw new \RuntimeException('Malformed inbound webhook job.');
        }

        $serviceId = $this->handlerMap[$endpointId] ?? null;
        if ($serviceId === null || !$this->handlerLocator->has($serviceId)) {
            throw new \RuntimeException('Inbound webhook handler is not registered.');
        }

        try {
            $handler = $this->handlerLocator->get($serviceId);
            if (!$handler instanceof InboundWebhookHandlerInterface) {
                throw new \RuntimeException('Inbound webhook handler contract mismatch.');
            }
            $handler->handle($body);
        } catch (Throwable $e) {
            $this->quarantine($endpointId, $e);
            throw $e;
        }
    }

    private function quarantine(string $endpointId, Throwable $e): void
    {
        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            '[%s] inbound webhook %s handler failed: %s',
            date('Y-m-d H:i:s'),
            $endpointId,
            $e->getMessage(),
        );
        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
