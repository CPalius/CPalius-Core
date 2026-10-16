<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Http\CspViolationAdvisor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CspViolationAdvisorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function blocked(): iterable
    {
        yield 'full url shrinks to the origin' => ['https://cdn.example.com/a/b.js?x=1', 'https://cdn.example.com'];
        yield 'port is kept' => ['https://cdn.example.com:8443/x', 'https://cdn.example.com:8443'];
        yield 'host is lowercased' => ['HTTPS://CDN.Example.COM/x', 'https://cdn.example.com'];
        yield 'empty means inline' => ['', 'inline'];
        yield 'eval keyword' => ['eval', 'eval'];
        yield 'data scheme' => ['data:image/png;base64,AAAA', 'data'];
        yield 'javascript scheme is not an origin' => ['javascript:alert(1)', 'javascript'];
    }

    #[DataProvider('blocked')]
    public function testClassify(string $input, string $expected): void
    {
        self::assertSame($expected, CspViolationAdvisor::classify($input));
    }
}
