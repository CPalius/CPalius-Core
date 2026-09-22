<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Http;

use App\Core\Security\Http\LoginTargetPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(LoginTargetPath::class)]
final class LoginTargetPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function targets(): iterable
    {
        yield 'forum page' => ['/forums/thread/12-hello', true];
        yield 'aacp desk' => ['/aacp', true];
        yield 'studio tools' => ['/admin/tools/rebuild', true];
        yield 'absolute profile' => ['https://site.example/hesap/profil', true];
        yield 'home' => ['/', true];
        yield 'live-feed query' => ['/aacp/telemetry/live-feed?after_id=6301', false];
        yield 'absolute live-feed' => ['https://site.example/aacp/telemetry/live-feed?after_id=1', false];
        yield 'metrics' => ['/aacp/system/metrics', false];
        yield 'pulse' => ['/hesap/nabiz', false];
        yield 'live search' => ['/tr/ara/canli', false];
        yield 'api' => ['/api/v1/nodes', false];
        yield 'login' => ['/login?session_expired=idle_timeout', false];
        yield 'member login' => ['/hesap/giris', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('targets')]
    public function testNavigable(string $target, bool $expected): void
    {
        self::assertSame($expected, LoginTargetPath::isNavigable($target));
    }

    public function testFetchAcceptIsAMachineRequest(): void
    {
        $request = Request::create('/aacp', 'GET');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Sec-Fetch-Dest', 'empty');

        self::assertTrue(LoginTargetPath::isMachineRequest($request));
        self::assertFalse(LoginTargetPath::isBrowserDocument($request));
    }

    public function testAddressBarGetIsABrowserDocument(): void
    {
        $request = Request::create('/aacp/telemetry/live-feed?after_id=1', 'GET');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');
        $request->headers->set('Sec-Fetch-Dest', 'document');

        self::assertTrue(LoginTargetPath::isBrowserDocument($request));
        self::assertTrue(LoginTargetPath::isMachineRequest($request));
    }
}
