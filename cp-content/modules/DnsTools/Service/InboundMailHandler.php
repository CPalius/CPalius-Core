<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Webhook\InboundWebhookHandlerInterface;

/**
 * Signed JSON from the site's catch-all / mail provider. Core verifies HMAC first.
 */
final class InboundMailHandler implements InboundWebhookHandlerInterface
{
    public function __construct(
        private readonly InboxService $inbox,
    ) {
    }

    public static function endpointId(): string
    {
        return 'dnstools.inbound-mail';
    }

    public function handle(array $payload): void
    {
        $to = trim((string) ($payload['to'] ?? $payload['recipient'] ?? ''));
        $raw = (string) ($payload['raw'] ?? $payload['message'] ?? '');
        if ($to === '' || $raw === '') {
            return;
        }

        $this->inbox->ingest($to, $raw);
    }
}
