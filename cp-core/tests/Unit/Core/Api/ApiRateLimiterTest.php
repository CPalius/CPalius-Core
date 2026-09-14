<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Api;

use App\Core\Api\ApiKey;
use App\Core\Api\ApiRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ApiRateLimiter::class)]
final class ApiRateLimiterTest extends TestCase
{
    public function testAllowsRequestsUnderTheLimitThenDenies(): void
    {
        $limiter = new ApiRateLimiter(new ArrayAdapter());
        $request = Request::create('/api/resources/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

        $allowed = 0;
        for ($i = 0; $i < ApiRateLimiter::ANONYMOUS_LIMIT + 5; ++$i) {
            if ($limiter->consume($request, null, 'api')) {
                ++$allowed;
            }
        }

        self::assertSame(ApiRateLimiter::ANONYMOUS_LIMIT, $allowed);
    }

    public function testAuthenticatedKeysGetTheHigherBucket(): void
    {
        $limiter = new ApiRateLimiter(new ArrayAdapter());
        $request = Request::create('/api/resources/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $key = $this->apiKey();

        $allowed = 0;
        for ($i = 0; $i < ApiRateLimiter::AUTHENTICATED_LIMIT + 5; ++$i) {
            if ($limiter->consume($request, $key, 'api')) {
                ++$allowed;
            }
        }

        self::assertSame(ApiRateLimiter::AUTHENTICATED_LIMIT, $allowed);
    }

    public function testFailsClosedWhenCacheThrows(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('cache down'));

        $limiter = new ApiRateLimiter($pool);
        $request = Request::create('/api/resources/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

        self::assertFalse($limiter->consume($request, null, 'api'));
    }

    private function apiKey(): ApiKey
    {
        return new ApiKey(
            id: 'k1',
            label: 'test',
            hash: str_repeat('a', 64),
            lastFourChars: 'abcd',
            createdAt: new \DateTimeImmutable(),
            active: true,
        );
    }
}
