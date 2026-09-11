<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\OriginCachePolicy;
use App\Core\Performance\VarnishCachePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(OriginCachePolicy::class)]
final class OriginCachePolicyTest extends TestCase
{
    private OriginCachePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new OriginCachePolicy(new VarnishCachePolicy());
    }

    public function testAnonymousGetToPublicPathIsWritable(): void
    {
        $request = Request::create('/tr/blog/hello');

        self::assertTrue($this->policy->isCacheableWriteRequest($request));
        self::assertTrue($this->policy->isCacheableRequest($request));
    }

    public function testGuestSessionCookieBlocksServeButNotWrite(): void
    {
        $request = Request::create('/tr/blog/hello');
        $request->cookies->set('PHPSESSID', 'leftover');

        self::assertTrue($this->policy->isCacheableWriteRequest($request));
        self::assertFalse($this->policy->isCacheableRequest($request));
    }

    public function testAdminPathIsNeverCacheable(): void
    {
        $request = Request::create('/aacp/dashboard');

        self::assertFalse($this->policy->isCacheableWriteRequest($request));
        self::assertFalse($this->policy->isCacheableRequest($request));
    }

    public function testSetCookieBlocksResponse(): void
    {
        $ok = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        self::assertTrue($this->policy->isCacheableResponse($ok));

        $cookie = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $cookie->headers->setCookie(Cookie::create('cp_locale', 'tr'));
        self::assertFalse($this->policy->isCacheableResponse($cookie));
    }
}
