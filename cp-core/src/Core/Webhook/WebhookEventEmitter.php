<?php

declare(strict_types=1);

namespace App\Core\Webhook;

use App\Core\Queue\AsyncJobDispatcherInterface;
use App\Core\Queue\Entity\AsyncJob;
use App\Core\Webhook\Repository\WebhookSubscriptionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Fan-out to the queue only. Never performs HTTP in the caller process.
 */
final class WebhookEventEmitter
{
    /** @var list<string> */
    private const REDACT_KEYS = ['password', 'secret', 'token', 'hash', 'salt', 'apiKey', 'api_key', 'authorization'];

    public function __construct(
        private readonly WebhookSubscriptionRepository $subscriptions,
        private readonly AsyncJobDispatcherInterface $asyncJobBus,
        private readonly SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload, ?string $tenantId = null, ?string $resource = null): int
    {
        if (!$this->isSafeEventName($event)) {
            return 0;
        }

        $envelope = [
            'id' => Uuid::v7()->toRfc4122(),
            'occurred_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'event' => $event,
            'tenant' => $tenantId,
            'resource' => $resource,
            'payload' => $this->redact($payload),
        ];

        $queued = 0;
        foreach ($this->subscriptions->findDeliverableForEvent($event) as $subscription) {
            try {
                $this->ssrfGuard->assertSafeUrl($subscription->getUrl());
            } catch (\InvalidArgumentException) {
                $subscription->recordFailure();
                continue;
            }

            $this->asyncJobBus->dispatch(AsyncJob::TYPE_OUTBOUND_WEBHOOK, [
                'subscription_id' => $subscription->getId(),
                'envelope' => $envelope,
            ], $tenantId);
            ++$queued;
        }

        return $queued;
    }

    private function isSafeEventName(string $event): bool
    {
        return preg_match('/^[a-z][a-z0-9_.]{0,99}$/', $event) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $clean = [];
        $count = 0;
        foreach ($payload as $key => $value) {
            if ($count >= 32 || !\is_string($key)) {
                break;
            }
            if (\in_array($key, self::REDACT_KEYS, true)) {
                continue;
            }
            if (\is_array($value) || \is_object($value)) {
                continue;
            }
            if (\is_string($value) && \strlen($value) > 1024) {
                $value = substr($value, 0, 1024);
            }
            $clean[$key] = $value;
            ++$count;
        }

        return $clean;
    }
}
