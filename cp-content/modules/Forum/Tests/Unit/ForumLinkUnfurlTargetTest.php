<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Service\ForumLinkUnfurlService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The unfurl endpoint makes the server fetch a URL the caller chose, so the
 * question "may this address be fetched" is the whole security boundary.
 *
 * Only the pure half is exercised here — no network, no container. What the
 * fetch loop does with the answer (pin the resolved IP, re-validate every
 * redirect hop) cannot be asserted without a live HTTP server, but it can only
 * be as good as this predicate, and this predicate is cheap to keep honest.
 */
#[CoversClass(ForumLinkUnfurlService::class)]
final class ForumLinkUnfurlTargetTest extends TestCase
{
    /**
     * Built without its constructor. isPublicHttpUrl() reads no property — it
     * parses the URL and resolves the host — and the repository it would
     * otherwise need is final, so a double is not available either. Skipping
     * construction is both the smaller lie and the more honest one: the test
     * then depends on nothing the method does not.
     */
    private function service(): ForumLinkUnfurlService
    {
        /** @var ForumLinkUnfurlService $service */
        $service = (new \ReflectionClass(ForumLinkUnfurlService::class))->newInstanceWithoutConstructor();

        return $service;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'loopback by name' => ['http://localhost/admin'];
        yield 'loopback v4' => ['http://127.0.0.1/admin'];
        yield 'loopback v4 alternate' => ['http://127.1.2.3/'];
        yield 'loopback v6' => ['http://[::1]/'];
        yield 'private 10/8' => ['http://10.0.0.5/'];
        yield 'private 172.16/12' => ['http://172.16.4.9/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/'];
        yield 'link-local / cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'this-network 0/8' => ['http://0.0.0.0/'];
        yield 'unique local v6' => ['http://[fd00::1]/'];
        yield 'mdns suffix' => ['http://printer.local/'];
        yield 'internal suffix' => ['http://vault.internal/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://127.0.0.1:6379/_INFO'];
        yield 'ftp scheme' => ['ftp://example.com/x'];
        yield 'no scheme' => ['//127.0.0.1/'];
        yield 'empty' => [''];
        yield 'not a url' => ['javascript:alert(1)'];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesNonPublicTargets(string $url): void
    {
        self::assertFalse(
            $this->service()->isPublicHttpUrl($url),
            sprintf('"%s" must not be fetchable by the unfurl endpoint.', $url),
        );
    }

    /**
     * A host that resolves to nothing is refused rather than attempted: an
     * unresolvable name is not evidence that the address behind it is public.
     */
    public function testRefusesAHostThatDoesNotResolve(): void
    {
        self::assertFalse(
            $this->service()->isPublicHttpUrl('http://this-host-should-not-resolve.invalid/'),
            'An unresolvable host must be refused, not attempted.',
        );
    }

    /**
     * The predicate has to say yes to something, or the endpoint is merely
     * broken rather than safe. A literal public address avoids depending on DNS
     * from the test runner.
     */
    public function testAcceptsAPublicAddress(): void
    {
        self::assertTrue($this->service()->isPublicHttpUrl('https://93.184.216.34/'));
        self::assertTrue($this->service()->isPublicHttpUrl('http://93.184.216.34:8080/page'));
    }
}
