<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Performance;

use App\Core\Performance\VarnishCachePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VarnishCachePolicy::class)]
final class VarnishCachePolicyTest extends TestCase
{
    private VarnishCachePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new VarnishCachePolicy();
    }

    public function testParseExcludesAcceptsNewlinesAndCommas(): void
    {
        $parsed = $this->policy->parseExcludes("/aacp\n/admin, /login\nhesap");

        self::assertSame(['/aacp', '/admin', '/login', '/hesap'], $parsed);
    }

    public function testHardPrivatePathsCoverAacpAndLocalePrefixedAccount(): void
    {
        self::assertTrue($this->policy->isHardPrivatePath('/aacp/performance'));
        self::assertTrue($this->policy->isHardPrivatePath('/admin/blog'));
        self::assertTrue($this->policy->isHardPrivatePath('/tr/hesap/profil'));
        self::assertFalse($this->policy->isHardPrivatePath('/blog/hello'));
    }

    public function testExcludedPathsDoNotTreatRootAsAMatch(): void
    {
        self::assertFalse($this->policy->isExcludedPath('/', ['/']));
        self::assertTrue($this->policy->isExcludedPath('/forum/new', ['/forum']));
        self::assertFalse($this->policy->isExcludedPath('/forums', ['/forum']));
    }

    public function testNormalizeTtlFallsBackAndCapsAtOneDay(): void
    {
        self::assertSame(120, $this->policy->normalizeTtl(0));
        self::assertSame(90, $this->policy->normalizeTtl('90'));
        self::assertSame(86400, $this->policy->normalizeTtl(999999));
    }
}
