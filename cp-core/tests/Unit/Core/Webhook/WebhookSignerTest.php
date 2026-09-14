<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Webhook;

use App\Core\Webhook\WebhookSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookSigner::class)]
final class WebhookSignerTest extends TestCase
{
    private WebhookSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new WebhookSigner();
    }

    public function testSignThenVerifyRoundTrips(): void
    {
        $envelope = ['id' => 'evt_1', 'event' => 'resource.invoice.created'];
        $now = 1_700_000_000;

        $header = $this->signer->sign($envelope, 'whsec_test', $now);
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertTrue($this->signer->verify($header, $body, 'whsec_test', $now + 5));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $envelope = ['id' => 'evt_1'];
        $header = $this->signer->sign($envelope, 'right', 1_700_000_000);
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertFalse($this->signer->verify($header, $body, 'wrong', 1_700_000_000));
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $header = $this->signer->sign(['id' => 'evt_1'], 'whsec_test', 1_700_000_000);

        self::assertFalse($this->signer->verify($header, '{"id":"evt_2"}', 'whsec_test', 1_700_000_000));
    }

    public function testVerifyRejectsStaleTimestamp(): void
    {
        $now = 1_700_000_000;
        $header = $this->signer->sign(['id' => 'evt_1'], 'whsec_test', $now);
        $body = json_encode(['id' => 'evt_1'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertFalse($this->signer->verify($header, $body, 'whsec_test', $now + WebhookSigner::MAX_SKEW_SECONDS + 1));
    }

    public function testVerifyRejectsMalformedHeader(): void
    {
        self::assertFalse($this->signer->verify('garbage', '{}', 'whsec_test', 1_700_000_000));
        self::assertFalse($this->signer->verify('', '{}', 'whsec_test', 1_700_000_000));
    }
}
