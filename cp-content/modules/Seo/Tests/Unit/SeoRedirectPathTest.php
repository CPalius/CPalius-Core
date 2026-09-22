<?php

declare(strict_types=1);

namespace Modules\Seo\Tests\Unit;

use Modules\Seo\Redirect\SeoRedirectPath;
use PHPUnit\Framework\TestCase;

final class SeoRedirectPathTest extends TestCase
{
    public function testSourceStripsHostAndSlash(): void
    {
        self::assertSame('tr/eski-yazi', SeoRedirectPath::normalizeSource('https://www.example.com/tr/eski-yazi'));
        self::assertSame('tr/eski-yazi', SeoRedirectPath::normalizeSource('/tr/eski-yazi'));
    }

    public function testSourceRejectsTraversal(): void
    {
        self::assertNull(SeoRedirectPath::normalizeSource('../secret'));
        self::assertNull(SeoRedirectPath::normalizeSource(''));
    }

    public function testTargetAcceptsRelativeAndHttps(): void
    {
        self::assertSame('/tr/yeni', SeoRedirectPath::normalizeTarget('tr/yeni'));
        self::assertSame('https://example.com/x', SeoRedirectPath::normalizeTarget('https://example.com/x'));
    }

    public function testTargetRejectsDangerousSchemes(): void
    {
        self::assertNull(SeoRedirectPath::normalizeTarget('javascript:alert(1)'));
        self::assertNull(SeoRedirectPath::normalizeTarget('//evil.example/phish'));
        self::assertNull(SeoRedirectPath::normalizeTarget('data:text/html,hi'));
    }
}
