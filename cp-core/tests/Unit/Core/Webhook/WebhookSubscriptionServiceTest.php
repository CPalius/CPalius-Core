<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Webhook;

use App\Core\Security\SecretBox;
use App\Core\Webhook\Entity\WebhookSubscription;
use App\Core\Webhook\Repository\WebhookSubscriptionRepository;
use App\Core\Webhook\SsrfGuard;
use App\Core\Webhook\WebhookSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookSubscriptionService::class)]
final class WebhookSubscriptionServiceTest extends TestCase
{
    private WebhookSubscriptionService $service;
    private SecretBox $secretBox;

    protected function setUp(): void
    {
        $this->secretBox = new SecretBox('unit-test-secret');
        $this->service = new WebhookSubscriptionService(
            $this->createMock(WebhookSubscriptionRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->secretBox,
            new SsrfGuard(),
        );
    }

    public function testCreateReturnsPlaintextOnceAndPersistsOnlyCiphertext(): void
    {
        $result = $this->service->create('Billing', 'https://93.184.216.34/hook', ['resource.invoice.created']);

        self::assertStringStartsWith(WebhookSubscriptionService::SECRET_PREFIX, $result['secret']);

        $subscription = $result['subscription'];
        self::assertInstanceOf(WebhookSubscription::class, $subscription);
        self::assertNotSame($result['secret'], $subscription->getSecretCipher());
        self::assertSame(substr($result['secret'], -4), $subscription->getSecretLast4());
        self::assertSame($result['secret'], $this->secretBox->open($subscription->getSecretCipher()));
    }

    public function testCreateRejectsSsrfUrlBeforePersisting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('Bad', 'https://169.254.169.254/latest', ['resource.invoice.created']);
    }

    public function testCreateRejectsPlainHttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('Bad', 'http://example.com/hook', ['resource.invoice.created']);
    }

    public function testCreateRejectsWhenNoValidEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('Bad', 'https://93.184.216.34/hook', ['NOT VALID', '*']);
    }

    public function testCreateNormalisesEvents(): void
    {
        $result = $this->service->create('Billing', 'https://93.184.216.34/hook', [
            'Resource.Invoice.Created',
            ' resource.invoice.updated ',
            'resource.invoice.created',
        ]);

        self::assertSame(
            ['resource.invoice.created', 'resource.invoice.updated'],
            $result['subscription']->getEvents(),
        );
    }
}
