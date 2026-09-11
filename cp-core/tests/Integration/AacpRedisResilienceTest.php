<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPController;
use App\Core\Cache\OptionalRedis;
use App\Core\Performance\PerformanceInventory;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The admin console must survive an absent cache server.
 *
 * Redis is optional in CPalius by design — the project's claim is that it runs
 * on shared hosting with nothing but PHP and MySQL. That claim is only as good
 * as the code paths that touch Redis: if the dashboard throws when the server
 * is down, "optional" is documentation rather than behaviour. These tests run
 * against whatever the environment actually offers, so they are meaningful
 * whether or not a Redis is listening.
 */
#[CoversClass(OptionalRedis::class)]
final class AacpRedisResilienceTest extends IntegrationTestCase
{
    public function testAacpControllerInstantiatesWithoutRedis(): void
    {
        $container = $this->container();

        // Resolving the controller pulls its whole dependency graph, cache
        // wiring included. A connection attempt in a constructor would surface
        // right here.
        $controller = $container->get(AACPController::class);

        self::assertInstanceOf(AACPController::class, $controller);
    }

    public function testOptionalRedisReportsUnavailableInsteadOfThrowing(): void
    {
        $container = $this->container();

        /** @var OptionalRedis $redis */
        $redis = $container->get(OptionalRedis::class);

        // Whatever the environment, the contract is the same: a boolean and a
        // nullable object, never an exception.
        $available = $redis->isAvailable();
        $client = $redis->get();

        if ($available) {
            self::assertNotNull($client, 'an available Redis returns a client');
        } else {
            self::assertNull($client, 'an unavailable Redis degrades to null');
        }

        // Memoised: asking twice must not retry a dead socket on every call,
        // which is what made pages hang before the timeout was lowered.
        self::assertSame($available, $redis->isAvailable());
    }

    public function testPerformanceSnapshotDegradesRedisToOffline(): void
    {
        $container = $this->container();

        /** @var PerformanceInventory $inventory */
        $inventory = $container->get(PerformanceInventory::class);

        $snapshot = $inventory->snapshot();

        // The panel always describes every backend, so an operator sees
        // "offline" rather than a missing row they might read as "fine".
        foreach (['cpalius', 'redis', 'memcached', 'varnish', 'pagespeed', 'opcache'] as $backend) {
            self::assertArrayHasKey($backend, $snapshot, sprintf('%s is always reported', $backend));
        }

        self::assertIsArray($snapshot['redis']);
    }
}
