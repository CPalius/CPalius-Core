<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Webhook;

use App\Core\Queue\AsyncJobDispatcherInterface;
use App\Core\Queue\Entity\AsyncJob;
use App\Core\Webhook\Entity\WebhookSubscription;
use App\Core\Webhook\Repository\WebhookSubscriptionRepository;
use App\Core\Webhook\SsrfGuard;
use App\Core\Webhook\WebhookEventEmitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookEventEmitter::class)]
final class WebhookEventEmitterTest extends TestCase
{
    public function testRejectsUnsafeEventNameWithoutTouchingTheQueue(): void
    {
        $bus = $this->createMock(AsyncJobDispatcherInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $repository = $this->createMock(WebhookSubscriptionRepository::class);
        $repository->expects(self::never())->method('findDeliverableForEvent');

        $emitter = new WebhookEventEmitter($repository, $bus, new SsrfGuard());

        self::assertSame(0, $emitter->emit('Resource.Invoice.Created', ['id' => '1']));
    }

    public function testFansOutToQueueAndRedactsSecrets(): void
    {
        $subscription = new WebhookSubscription('acct', 'https://93.184.216.34/hook', 'cipher', 'abcd', ['resource.invoice.created']);

        $repository = $this->createMock(WebhookSubscriptionRepository::class);
        $repository->method('findDeliverableForEvent')->with('resource.invoice.created')->willReturn([$subscription]);

        $captured = null;
        $bus = $this->createMock(AsyncJobDispatcherInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (string $type, array $payload) use (&$captured): AsyncJob {
                self::assertSame(AsyncJob::TYPE_OUTBOUND_WEBHOOK, $type);
                $captured = $payload;

                return new AsyncJob($type, $payload);
            });

        $emitter = new WebhookEventEmitter($repository, $bus, new SsrfGuard());

        $queued = $emitter->emit('resource.invoice.created', [
            'id' => '42',
            'password' => 'hunter2',
            'api_key' => 'cpk_secret',
            'nested' => ['drop' => 'me'],
        ], 'acme', 'invoice');

        self::assertSame(1, $queued);
        self::assertArrayHasKey('envelope', $captured);
        self::assertSame('resource.invoice.created', $captured['envelope']['event']);
        self::assertSame(['id' => '42'], $captured['envelope']['payload']);
    }

    public function testSkipsSubscriptionWhoseUrlBecameUnsafe(): void
    {
        $subscription = new WebhookSubscription('bad', 'https://127.0.0.1/hook', 'cipher', 'abcd', ['resource.invoice.created']);

        $repository = $this->createMock(WebhookSubscriptionRepository::class);
        $repository->method('findDeliverableForEvent')->willReturn([$subscription]);

        $bus = $this->createMock(AsyncJobDispatcherInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $emitter = new WebhookEventEmitter($repository, $bus, new SsrfGuard());

        self::assertSame(0, $emitter->emit('resource.invoice.created', ['id' => '1']));
        self::assertSame(1, $subscription->getConsecutiveFailures());
    }
}
