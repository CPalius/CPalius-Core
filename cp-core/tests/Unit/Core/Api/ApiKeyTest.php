<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Api;

use App\Core\Api\ApiKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiKey::class)]
final class ApiKeyTest extends TestCase
{
    public function testArrayRoundTrip(): void
    {
        $key = new ApiKey(
            id: 'abc',
            label: 'Accounting',
            hash: str_repeat('f', 64),
            lastFourChars: 'wxyz',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            active: true,
            capabilities: ['invoice.read', 'invoice.read', 'ticket.create'],
            tenantId: 'acme',
            ipAllowlist: ['203.0.113.0/24'],
            expiresAt: new \DateTimeImmutable('2026-06-01T00:00:00+00:00'),
        );

        $restored = ApiKey::fromArray($key->toArray());

        self::assertSame('abc', $restored->id);
        self::assertSame(['invoice.read', 'ticket.create'], $restored->capabilities);
        self::assertSame('acme', $restored->tenantId);
        self::assertSame(['203.0.113.0/24'], $restored->ipAllowlist);
        self::assertNotNull($restored->expiresAt);
    }

    public function testPublicArrayNeverLeaksHash(): void
    {
        $key = new ApiKey('id', 'l', str_repeat('a', 64), 'aaaa', new \DateTimeImmutable(), true);

        self::assertArrayNotHasKey('hash', $key->toPublicArray());
        self::assertArrayHasKey('hash', $key->toArray());
    }

    public function testHasCapabilityIsExactMatch(): void
    {
        $key = new ApiKey('id', 'l', str_repeat('a', 64), 'aaaa', new \DateTimeImmutable(), true, ['invoice.read']);

        self::assertTrue($key->hasCapability('invoice.read'));
        self::assertFalse($key->hasCapability('invoice'));
        self::assertFalse($key->hasCapability('invoice.read.all'));
    }

    public function testIsExpired(): void
    {
        $past = new ApiKey('id', 'l', str_repeat('a', 64), 'aaaa', new \DateTimeImmutable(), true, [], null, [], new \DateTimeImmutable('2000-01-01'));
        $future = new ApiKey('id', 'l', str_repeat('a', 64), 'aaaa', new \DateTimeImmutable(), true, [], null, [], new \DateTimeImmutable('2999-01-01'));
        $never = new ApiKey('id', 'l', str_repeat('a', 64), 'aaaa', new \DateTimeImmutable(), true);

        self::assertTrue($past->isExpired());
        self::assertFalse($future->isExpired());
        self::assertFalse($never->isExpired());
    }
}
