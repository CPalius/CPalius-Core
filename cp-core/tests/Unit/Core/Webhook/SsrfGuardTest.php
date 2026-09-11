<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Webhook;

use App\Core\Webhook\SsrfGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SsrfGuard::class)]
final class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    #[DataProvider('blockedUrlProvider')]
    public function testAssertSafeUrlRejects(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard->assertSafeUrl($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'plain http' => ['http://example.com/hook'];
        yield 'no scheme' => ['example.com/hook'];
        yield 'ftp scheme' => ['ftp://example.com/hook'];
        yield 'credentials in url' => ['https://user:pass@example.com/hook'];
        yield 'localhost' => ['https://localhost/hook'];
        yield 'sub.localhost' => ['https://api.localhost/hook'];
        yield 'loopback ip' => ['https://127.0.0.1/hook'];
        yield 'private 10/8' => ['https://10.1.2.3/hook'];
        yield 'private 172.16/12' => ['https://172.16.9.9/hook'];
        yield 'private 192.168/16' => ['https://192.168.1.1/hook'];
        yield 'link-local / cloud metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'ipv6 loopback' => ['https://[::1]/hook'];
        yield 'ipv6 ULA' => ['https://[fd00::1]/hook'];
    }

    public function testAssertSafeUrlAllowsPublicLiteralIp(): void
    {
        $this->guard->assertSafeUrl('https://93.184.216.34/hook');
        $this->addToAssertionCount(1);
    }

    public function testAssertSafeUrlRejectsIpv4MappedIpv6Loopback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard->assertSafeUrl('https://[::ffff:127.0.0.1]/hook');
    }
}
