<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\TwoFactor\TotpGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Tests\Unit\Core\Security\Support\SecurityClock;

#[CoversClass(TotpGenerator::class)]
final class TotpGeneratorTest extends TestCase
{
    /**
     * RFC 6238 Appendix B publishes its vectors against the ASCII seed
     * "12345678901234567890". Base32 of those twenty bytes is the constant below,
     * which is what an authenticator app would actually be given.
     */
    private const RFC_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TotpGenerator $totp;

    public static function setUpBeforeClass(): void
    {
        SecurityClock::install();
    }

    protected function setUp(): void
    {
        $this->totp = new TotpGenerator();
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function rfc6238Vectors(): iterable
    {
        // RFC 6238 prints eight digits; the six-digit code is its last six, which
        // is what DIGITS = 6 produces.
        yield 'T=59 (first step)' => [59, '287082'];
        yield 'T=1111111109 (step boundary below)' => [1111111109, '081804'];
        yield 'T=1111111111 (step boundary above)' => [1111111111, '050471'];
        yield 'T=1234567890' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
        yield 'T=20000000000 (past 2^32 seconds)' => [20000000000, '353130'];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testMatchesOfficialRfc6238Vectors(int $timestamp, string $expected): void
    {
        self::assertSame($expected, $this->totp->currentCode(self::RFC_SECRET_BASE32, $timestamp));
        self::assertTrue($this->totp->verify(self::RFC_SECRET_BASE32, $expected, 0, $timestamp));
    }

    public function testSecretIsThirtyTwoBase32CharactersAndDecodable(): void
    {
        $secret = $this->totp->generateSecret();

        // 20 random bytes encode to exactly 32 base32 characters with no padding.
        self::assertSame(32, \strlen($secret));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        // An undecodable secret yields '', so a real code proves the round-trip.
        self::assertNotSame('', $this->totp->currentCode($secret, 1700000000));
    }

    public function testTwoGeneratedSecretsDiffer(): void
    {
        self::assertNotSame($this->totp->generateSecret(), $this->totp->generateSecret());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function base32Secrets(): iterable
    {
        yield 'canonical' => [self::RFC_SECRET_BASE32, true];
        yield 'lowercase is accepted' => [strtolower(self::RFC_SECRET_BASE32), true];
        yield 'spaced as shown by apps' => ['GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', true];
        yield 'dash separated' => ['GEZD-GNBV-GY3T-QOJQ-GEZD-GNBV-GY3T-QOJQ', true];
        yield 'padded with =' => ['GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ====', true];
        yield 'empty' => ['', false];
        yield 'whitespace only' => ['   ', false];
        yield 'single character is under one byte' => ['A', false];
        yield 'digit 1 is outside the alphabet' => ['ABCD1EFG', false];
        yield 'digit 0 is outside the alphabet' => ['ABCD0EFG', false];
        yield 'digit 8 is outside the alphabet' => ['ABCD8EFG', false];
        yield 'punctuation' => ['!!!!!!!!', false];
    }

    #[DataProvider('base32Secrets')]
    public function testBase32DecodeAcceptsOnlyWellFormedSecrets(string $secret, bool $decodable): void
    {
        $code = $this->totp->currentCode($secret, 1700000000);

        if ($decodable) {
            self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        } else {
            self::assertSame('', $code, 'An undecodable secret must not produce a code.');
        }
    }

    public function testSpacedAndPaddedSecretsProduceTheSameCodeAsTheCanonicalForm(): void
    {
        $canonical = $this->totp->currentCode(self::RFC_SECRET_BASE32, 59);

        self::assertSame($canonical, $this->totp->currentCode(strtolower(self::RFC_SECRET_BASE32), 59));
        self::assertSame($canonical, $this->totp->currentCode('GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', 59));
        self::assertSame($canonical, $this->totp->currentCode(self::RFC_SECRET_BASE32.'====', 59));
    }

    public function testVerifyRejectsEveryCodeWhenTheSecretIsUndecodable(): void
    {
        self::assertFalse($this->totp->verify('ABCD1EFG', '000000', 1, 59));
        self::assertNull($this->totp->matchedCounter('', '287082', 1, 59));
    }

    public function testDriftWindowAcceptsNeighbouringStepsAndNothingFurther(): void
    {
        $now = 1700000000;
        $step = TotpGenerator::PERIOD;

        $previous = $this->totp->currentCode(self::RFC_SECRET_BASE32, $now - $step);
        $current = $this->totp->currentCode(self::RFC_SECRET_BASE32, $now);
        $next = $this->totp->currentCode(self::RFC_SECRET_BASE32, $now + $step);
        $twoAgo = $this->totp->currentCode(self::RFC_SECRET_BASE32, $now - (2 * $step));

        self::assertTrue($this->totp->verify(self::RFC_SECRET_BASE32, $current, 1, $now));
        self::assertTrue($this->totp->verify(self::RFC_SECRET_BASE32, $previous, 1, $now));
        self::assertTrue($this->totp->verify(self::RFC_SECRET_BASE32, $next, 1, $now));
        self::assertFalse($this->totp->verify(self::RFC_SECRET_BASE32, $twoAgo, 1, $now));

        // drift = 0 narrows the window to the current step only.
        self::assertTrue($this->totp->verify(self::RFC_SECRET_BASE32, $current, 0, $now));
        self::assertFalse($this->totp->verify(self::RFC_SECRET_BASE32, $previous, 0, $now));
        self::assertFalse($this->totp->verify(self::RFC_SECRET_BASE32, $next, 0, $now));
    }

    public function testDriftIsClampedSoAnAbsurdSettingCannotWidenTheWindowForever(): void
    {
        $now = 1700000000;
        $farPast = $this->totp->currentCode(self::RFC_SECRET_BASE32, $now - (50 * TotpGenerator::PERIOD));

        // The implementation clamps drift to 10 periods either side.
        self::assertFalse($this->totp->verify(self::RFC_SECRET_BASE32, $farPast, 9999, $now));
    }

    public function testMatchedCounterReturnsTheStepTheCodeBelongsTo(): void
    {
        $now = 1700000000;
        $counter = intdiv($now, TotpGenerator::PERIOD);

        self::assertSame(
            $counter,
            $this->totp->matchedCounter(self::RFC_SECRET_BASE32, $this->totp->currentCode(self::RFC_SECRET_BASE32, $now), 1, $now),
        );
        self::assertSame(
            $counter - 1,
            $this->totp->matchedCounter(self::RFC_SECRET_BASE32, $this->totp->currentCode(self::RFC_SECRET_BASE32, $now - TotpGenerator::PERIOD), 1, $now),
        );
    }

    /**
     * The counter a caller stores to refuse a replay is exactly the counter that
     * matched, so replaying the same code inside its window is detectable by a
     * simple "<=" comparison. Proven here at the primitive level; TwoFactorService
     * is what enforces it.
     */
    public function testSameCodeKeepsMatchingTheSameCounterWithinItsWindow(): void
    {
        $start = 1700000000 - (1700000000 % TotpGenerator::PERIOD);
        $code = $this->totp->currentCode(self::RFC_SECRET_BASE32, $start);

        $first = $this->totp->matchedCounter(self::RFC_SECRET_BASE32, $code, 0, $start);
        $second = $this->totp->matchedCounter(self::RFC_SECRET_BASE32, $code, 0, $start + 29);

        self::assertNotNull($first);
        self::assertSame($first, $second, 'A replay inside the window must resolve to the already-used counter.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function submittedCodes(): iterable
    {
        yield 'exact' => ['287082', true];
        yield 'with a space separator' => ['287 082', true];
        yield 'with a dash separator' => ['287-082', true];
        yield 'surrounded by whitespace' => ["  287082\n", true];
        yield 'too short' => ['28708', false];
        yield 'too long' => ['2870821', false];
        yield 'empty' => ['', false];
        yield 'letters only' => ['abcdef', false];
        // Regression: the old normaliser stripped every non-digit, so a code
        // buried in arbitrary text authenticated.
        yield 'six digits buried in text' => ['abc287082xyz', false];
        yield 'six digits inside a longer number with punctuation' => ['2.8.7.0.8.2!', false];
    }

    #[DataProvider('submittedCodes')]
    public function testOnlyDigitsAndDisplaySeparatorsAreAcceptedAsACode(string $submitted, bool $accepted): void
    {
        self::assertSame($accepted, $this->totp->verify(self::RFC_SECRET_BASE32, $submitted, 0, 59));
    }

    public function testNegativeCounterNeverMatches(): void
    {
        // A timestamp before the epoch step 0 must not be verifiable at all.
        self::assertNull($this->totp->matchedCounter(self::RFC_SECRET_BASE32, '000000', 1, -100));
    }

    public function testProvisioningUriCarriesTheParametersAuthenticatorAppsExpect(): void
    {
        $uri = $this->totp->provisioningUri(self::RFC_SECRET_BASE32, 'ali@example.com', 'CPalius CMF');

        self::assertStringStartsWith('otpauth://totp/CPalius%20CMF:ali%40example.com?', $uri);
        self::assertStringContainsString('secret='.self::RFC_SECRET_BASE32, $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
