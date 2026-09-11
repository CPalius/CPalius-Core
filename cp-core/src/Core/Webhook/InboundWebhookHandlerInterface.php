<?php

declare(strict_types=1);

namespace App\Core\Webhook;

/**
 * Module inbound receiver. Core verifies the signature before this runs on the worker.
 */
interface InboundWebhookHandlerInterface
{
    public static function endpointId(): string;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
