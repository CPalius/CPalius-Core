<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Service\IpMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpMatcher::class)]
final class IpMatcherTest extends TestCase
{
    private IpMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new IpMatcher();
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function literalAddresses(): iterable
    {
        yield 'identical IPv4' => ['1.2.3.4', '1.2.3.4', true];
        yield 'different IPv4' => ['1.2.3.4', '1.2.3.5', false];
        yield 'identical IPv6' => ['2001:db8::1', '2001:db8::1', true];
        yield 'IPv6 case differences' => ['2001:DB8::1', '2001:db8::1', true];
        yield 'IPv4 against IPv6' => ['1.2.3.4', '2001:db8::1', false];
        yield 'empty ip' => ['', '1.2.3.4', false];
        yield 'empty pattern' => ['1.2.3.4', '', false];
        yield 'surrounding whitespace is trimmed' => ['  1.2.3.4 ', ' 1.2.3.4  ', true];
        yield 'garbage against garbage still compares as text' => ['garbage', 'garbage', true];
        yield 'garbage against an address' => ['garbage', '1.2.3.4', false];

        // Regression: literal comparison used to be strcasecmp(), so the same
        // address written another way walked straight past a ban — and an
        // allowlisted operator writing it another way got locked out.
        yield 'compressed vs expanded IPv6' => ['2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001', true];
        yield 'expanded vs compressed loopback' => ['0:0:0:0:0:0:0:1', '::1', true];
        yield 'IPv4-mapped IPv6 vs plain IPv4' => ['::ffff:1.2.3.4', '1.2.3.4', true];
        yield 'plain IPv4 vs IPv4-mapped IPv6' => ['1.2.3.4', '::ffff:1.2.3.4', true];
        yield 'IPv4-mapped IPv6 vs a different IPv4' => ['::ffff:1.2.3.4', '1.2.3.5', false];
    }

    #[DataProvider('literalAddresses')]
    public function testLiteralMatching(string $ip, string $pattern, bool $expected): void
    {
        self::assertSame($expected, $this->matcher->matches($ip, $pattern));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cidrRanges(): iterable
    {
        yield 'byte aligned /24 inside' => ['1.2.3.4', '1.2.3.0/24', true];
        yield 'byte aligned /24 outside' => ['1.2.4.4', '1.2.3.0/24', false];
        yield '/8 inside' => ['10.200.30.40', '10.0.0.0/8', true];
        yield '/8 outside' => ['11.200.30.40', '10.0.0.0/8', false];

        // Non byte-aligned prefixes are where a hand-written mask goes wrong.
        yield '/23 lower half' => ['1.2.2.7', '1.2.2.0/23', true];
        yield '/23 upper half' => ['1.2.3.7', '1.2.2.0/23', true];
        yield '/23 just outside' => ['1.2.4.7', '1.2.2.0/23', false];
        yield '/23 with a subnet in the upper half' => ['1.2.3.4', '1.2.0.0/23', false];
        yield '/27 inside (host 5 of 0-31)' => ['10.0.0.5', '10.0.0.0/27', true];
        yield '/27 boundary (host 31)' => ['10.0.0.31', '10.0.0.0/27', true];
        yield '/27 just outside (host 32)' => ['10.0.0.32', '10.0.0.0/27', false];
        yield '/12 inside RFC1918' => ['172.20.1.1', '172.16.0.0/12', true];
        yield '/12 just outside RFC1918' => ['172.32.1.1', '172.16.0.0/12', false];
        yield '/12 just below RFC1918' => ['172.15.255.255', '172.16.0.0/12', false];

        // Degenerate prefixes.
        yield '/0 matches every IPv4' => ['203.0.113.9', '0.0.0.0/0', true];
        yield '/0 written on another subnet still matches every IPv4' => ['203.0.113.9', '10.0.0.0/0', true];
        yield '/32 exact hit' => ['1.2.3.4', '1.2.3.4/32', true];
        yield '/32 miss' => ['1.2.3.5', '1.2.3.4/32', false];

        // IPv6.
        yield 'IPv6 /32 inside' => ['2001:db8::1', '2001:db8::/32', true];
        yield 'IPv6 /32 outside' => ['2001:db9::1', '2001:db8::/32', false];
        yield 'IPv6 /64 inside' => ['2001:db8:0:1::5', '2001:db8:0:1::/64', true];
        yield 'IPv6 /64 outside' => ['2001:db8:0:2::5', '2001:db8:0:1::/64', false];
        yield 'IPv6 /128 exact hit' => ['2001:db8::1', '2001:db8::1/128', true];
        yield 'IPv6 /128 miss' => ['2001:db8::2', '2001:db8::1/128', false];
        yield 'IPv6 /0 matches every IPv6' => ['2001:db8::1', '::/0', true];
        yield 'IPv6 /127 inside' => ['2001:db8::1', '2001:db8::/127', true];
        yield 'IPv6 /127 outside' => ['2001:db8::2', '2001:db8::/127', false];

        // Family mismatch is a non-match, never an error.
        yield 'IPv4 against an IPv6 range' => ['1.2.3.4', '2001:db8::/32', false];
        yield 'IPv4 against the IPv6 default route' => ['1.2.3.4', '::/0', false];
        yield 'IPv6 against an IPv4 range' => ['2001:db8::1', '10.0.0.0/8', false];
        yield 'IPv6 against the IPv4 default route' => ['2001:db8::1', '0.0.0.0/0', false];

        // Regression: a dual-stack listener reports IPv4 clients in mapped form,
        // which used to mismatch on length and evade every IPv4 CIDR ban.
        yield 'IPv4-mapped IPv6 inside an IPv4 range' => ['::ffff:1.2.3.4', '1.2.3.0/24', true];
        yield 'IPv4-mapped IPv6 outside an IPv4 range' => ['::ffff:1.2.4.4', '1.2.3.0/24', false];
        yield 'IPv4 inside a mapped IPv4 range' => ['1.2.3.4', '::ffff:1.2.3.0/24', true];

        // Malformed input never raises, only fails to match.
        yield 'prefix beyond the IPv4 family' => ['1.2.3.4', '1.2.3.0/33', false];
        yield 'prefix beyond the IPv6 family' => ['2001:db8::1', '2001:db8::/129', false];
        yield 'three-digit nonsense prefix' => ['1.2.3.4', '1.2.3.0/999', false];
        yield 'four-digit prefix is rejected by the grammar' => ['1.2.3.4', '1.2.3.0/0024', false];
        yield 'negative prefix' => ['1.2.3.4', '1.2.3.0/-1', false];
        yield 'empty prefix' => ['1.2.3.4', '1.2.3.0/', false];
        yield 'non-numeric prefix' => ['1.2.3.4', '1.2.3.0/abc', false];
        yield 'unparseable subnet' => ['1.2.3.4', 'garbage/24', false];
        yield 'unparseable ip against a range' => ['garbage', '1.2.3.0/24', false];
        yield 'double slash' => ['1.2.3.4', '1.2.3.0/24/24', false];
    }

    #[DataProvider('cidrRanges')]
    public function testCidrMatching(string $ip, string $pattern, bool $expected): void
    {
        self::assertSame($expected, $this->matcher->matches($ip, $pattern));
    }

    public function testMatchesAnyStopsAtTheFirstHitAndIsFalseForAnEmptyList(): void
    {
        self::assertFalse($this->matcher->matchesAny('1.2.3.4', []));
        self::assertFalse($this->matcher->matchesAny('1.2.3.4', ['10.0.0.0/8', '192.168.0.0/16']));
        self::assertTrue($this->matcher->matchesAny('1.2.3.4', ['10.0.0.0/8', '1.2.3.0/24']));
        self::assertTrue($this->matcher->matchesAny('::ffff:10.0.0.7', ['10.0.0.0/8']));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function patterns(): iterable
    {
        yield 'IPv4 literal' => ['1.2.3.4', true];
        yield 'IPv6 literal' => ['2001:db8::1', true];
        yield 'loopback' => ['::1', true];
        yield 'IPv4 CIDR' => ['1.2.3.0/24', true];
        yield 'IPv6 CIDR' => ['2001:db8::/32', true];
        yield 'IPv6 /128' => ['0:0:0:0:0:0:0:0/128', true];
        yield 'mapped IPv4 CIDR validates against 32 bits' => ['::ffff:10.0.0.0/8', true];
        yield 'empty' => ['', false];
        yield 'whitespace' => ['   ', false];
        yield 'not an address' => ['abc', false];
        yield 'IPv4 prefix out of range' => ['1.2.3.0/33', false];
        yield 'IPv6 prefix out of range' => ['2001:db8::/129', false];
        yield 'four-digit prefix' => ['1.2.3.0/0024', false];
        yield 'too long to be any address' => [str_repeat('9', 46), false];
    }

    #[DataProvider('patterns')]
    public function testIsValidPattern(string $pattern, bool $expected): void
    {
        self::assertSame($expected, $this->matcher->isValidPattern($pattern));
    }

    public function testParseListAcceptsOperatorTypedSeparatorsAndDropsInvalidEntries(): void
    {
        $raw = "1.2.3.4\n10.0.0.0/8, 192.168.1.1;  2001:db8::/32\n\nnonsense\n1.2.3.0/99\n";

        self::assertSame(
            ['1.2.3.4', '10.0.0.0/8', '192.168.1.1', '2001:db8::/32'],
            $this->matcher->parseList($raw),
        );
    }

    public function testParseListOfEmptyInputIsEmpty(): void
    {
        self::assertSame([], $this->matcher->parseList(''));
        self::assertSame([], $this->matcher->parseList("  \n , ; "));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function canonicalForms(): iterable
    {
        yield 'IPv4 is already canonical' => ['1.2.3.4', '1.2.3.4'];
        yield 'expanded IPv6 compresses' => ['2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1'];
        yield 'uppercase IPv6 lowercases' => ['2001:DB8::1', '2001:db8::1'];
        yield 'mapped IPv4 unwraps' => ['::ffff:1.2.3.4', '1.2.3.4'];
        yield 'loopback compresses' => ['0:0:0:0:0:0:0:1', '::1'];
        yield 'non-address is returned trimmed' => ['  garbage  ', 'garbage'];
        yield 'empty stays empty' => ['', ''];
    }

    #[DataProvider('canonicalForms')]
    public function testCanonicalize(string $input, string $expected): void
    {
        self::assertSame($expected, $this->matcher->canonicalize($input));
    }

    public function testCanonicalizePatternLeavesThePrefixLengthAlone(): void
    {
        self::assertSame('2001:db8::/32', $this->matcher->canonicalizePattern('2001:0DB8:0000::/32'));
        self::assertSame('10.0.0.0/8', $this->matcher->canonicalizePattern('::ffff:10.0.0.0/8'));
        self::assertSame('1.2.3.4', $this->matcher->canonicalizePattern(' 1.2.3.4 '));
        self::assertSame('garbage/24', $this->matcher->canonicalizePattern('garbage/24'));
    }

    /**
     * A canonicalized pattern must still match everything the original matched —
     * otherwise storing the canonical form would quietly narrow a ban.
     */
    public function testCanonicalizationPreservesMatching(): void
    {
        $cases = [
            ['2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001'],
            ['10.0.0.7', '::ffff:10.0.0.0/8'],
            ['1.2.3.4', '1.2.3.0/24'],
        ];

        foreach ($cases as [$ip, $pattern]) {
            self::assertTrue($this->matcher->matches($ip, $pattern), $pattern);
            self::assertTrue(
                $this->matcher->matches($ip, $this->matcher->canonicalizePattern($pattern)),
                'canonical: '.$this->matcher->canonicalizePattern($pattern),
            );
        }
    }
}
