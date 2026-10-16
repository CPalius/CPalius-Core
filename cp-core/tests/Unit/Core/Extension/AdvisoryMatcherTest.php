<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Extension;

use App\Core\Extension\AdvisoryMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvisoryMatcherTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'below the fixed version' => ['1.1.9', '<1.2.0', true];
        yield 'the fixed version itself' => ['1.2.0', '<1.2.0', false];
        yield 'numeric, not string, comparison' => ['1.10.0', '<1.9.0', false];
        yield 'closed range inside' => ['1.5.0', '>=1.0.0 <1.6.0', true];
        yield 'closed range outside' => ['1.6.0', '>=1.0.0 <1.6.0', false];
        yield 'operator apart from version' => ['0.9.0', '< 1.0.0', true];
        yield 'exact' => ['1.4.0', '=1.4.0', true];
        yield 'bare version means exact' => ['1.4.1', '1.4.0', false];
        yield 'second alternative' => ['2.0.3', '<1.0.0 || >=2.0.0 <2.0.5', true];
        yield 'every version' => ['9.9.9', '*', true];
        yield 'garbage matches nothing' => ['1.0.0', 'latest', false];
    }

    #[DataProvider('cases')]
    public function testAffects(string $version, string $range, bool $expected): void
    {
        self::assertSame($expected, AdvisoryMatcher::affects($version, $range));
    }

    public function testValidRejectsATypoSoItIsCaughtAtPublishTime(): void
    {
        self::assertTrue(AdvisoryMatcher::valid('<1.2.0 || >=2.0.0 <2.0.5'));
        self::assertFalse(AdvisoryMatcher::valid('latest'));
        self::assertFalse(AdvisoryMatcher::valid(''));
    }
}
