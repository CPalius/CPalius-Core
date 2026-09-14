<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Performance;

use App\Core\Performance\HttpHeaderProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpHeaderProbe::class)]
final class HttpHeaderProbeTest extends TestCase
{
    #[DataProvider('normalizeUrlProvider')]
    public function testNormalizeUrlPrefixesHttpWhenSchemeIsMissing(string $input, string $expected): void
    {
        self::assertSame($expected, HttpHeaderProbe::normalizeUrl($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizeUrlProvider(): array
    {
        return [
            'empty' => ['', ''],
            'whitespace' => ['  ', ''],
            'host only' => ['127.0.0.1', 'http://127.0.0.1'],
            'host with slash' => ['127.0.0.1/', 'http://127.0.0.1/'],
            'host with varnish port' => ['127.0.0.1:6081', 'http://127.0.0.1:6081'],
            'already http' => ['http://127.0.0.1:6081/', 'http://127.0.0.1:6081/'],
            'already https' => ['https://example.test/', 'https://example.test/'],
            'trimmed' => ['  127.0.0.1:6081  ', 'http://127.0.0.1:6081'],
        ];
    }

    #[DataProvider('composeUrlProvider')]
    public function testComposeUrlAppliesExplicitPort(string $url, int|string|null $port, string $expected): void
    {
        self::assertSame($expected, HttpHeaderProbe::composeUrl($url, $port));
    }

    /**
     * @return array<string, array{string, int|string|null, string}>
     */
    public static function composeUrlProvider(): array
    {
        return [
            'host plus varnish port' => ['127.0.0.1', 6081, 'http://127.0.0.1:6081/'],
            'url with slash' => ['http://127.0.0.1/', 6081, 'http://127.0.0.1:6081/'],
            'overrides existing port' => ['http://127.0.0.1:80/', 6081, 'http://127.0.0.1:6081/'],
            'ignores zero port' => ['http://127.0.0.1/', 0, 'http://127.0.0.1/'],
        ];
    }

    public function testHasVarnishSignatureDetectsCommonHeaders(): void
    {
        self::assertTrue(HttpHeaderProbe::hasVarnishSignature(['HTTP/1.1 200 OK', 'X-Varnish: 65531']));
        self::assertTrue(HttpHeaderProbe::hasVarnishSignature(['Via: 1.1 varnish (Varnish/7.5)']));
        self::assertTrue(HttpHeaderProbe::hasVarnishSignature(['Server: Varnish']));
        self::assertFalse(HttpHeaderProbe::hasVarnishSignature(['HTTP/1.1 200 OK', 'Server: nginx']));
    }
}
