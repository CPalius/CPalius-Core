<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\SecretBox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretBox::class)]
final class SecretBoxTest extends TestCase
{
    private const SECRET = 'a-very-long-kernel-secret-value-0123456789';

    private SecretBox $box;

    protected function setUp(): void
    {
        $this->box = new SecretBox(self::SECRET);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plaintexts(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['x'];
        yield 'base32 TOTP secret' => ['GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'];
        yield 'exactly one AES block' => [str_repeat('A', 16)];
        yield 'one byte over a block' => [str_repeat('A', 17)];
        yield 'utf-8 with Turkish characters' => ['şifre-çğüöı-İ'];
        yield 'binary' => ["\x00\x01\x02\xFF\xFE"];
        yield 'long' => [str_repeat('secret-', 1000)];
    }

    #[DataProvider('plaintexts')]
    public function testRoundTrip(string $plain): void
    {
        self::assertSame($plain, $this->box->open($this->box->seal($plain)));
    }

    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        $sealed = $this->box->seal('super-secret-smtp-password');

        self::assertStringNotContainsString('super-secret-smtp-password', $sealed);
        self::assertStringNotContainsString('super-secret-smtp-password', (string) base64_decode(substr($sealed, 3), true));
    }

    /**
     * A deterministic ciphertext leaks equality: two accounts with the same TOTP
     * secret, or the same password set twice, would be visible as identical rows.
     */
    public function testTwoSealsOfTheSamePlaintextDiffer(): void
    {
        $a = $this->box->seal('identical');
        $b = $this->box->seal('identical');

        self::assertNotSame($a, $b);
        self::assertSame('identical', $this->box->open($a));
        self::assertSame('identical', $this->box->open($b));
    }

    public function testAnotherAppSecretCannotOpenTheBox(): void
    {
        $sealed = $this->box->seal('totp-secret');

        $this->expectException(\RuntimeException::class);
        (new SecretBox('a-completely-different-kernel-secret'))->open($sealed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCiphertexts(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64 at all' => ['v2:!!!not-base64!!!'];
        yield 'base64 but far too short' => ['v2:'.base64_encode('short')];
        yield 'nonce only, no tag' => ['v2:'.base64_encode(random_bytes(12))];
        yield 'legacy marker with junk' => [base64_encode(random_bytes(8))];
        yield 'legacy marker with exactly one IV and nothing else' => [base64_encode(random_bytes(16))];
        yield 'plain text mistaken for ciphertext' => ['just-a-password'];
    }

    #[DataProvider('malformedCiphertexts')]
    public function testOpeningMalformedInputThrowsRatherThanReturningGarbage(string $sealed): void
    {
        $this->expectException(\RuntimeException::class);
        $this->box->open($sealed);
    }

    /**
     * The core reason the format moved to GCM: CBC has no authentication tag, so
     * anyone able to write the row could flip ciphertext bits and change the
     * plaintext we later act on. Every single-bit change must now be detected.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $sealed = $this->box->seal('the-real-secret-value');
        $raw = base64_decode(substr($sealed, 3), true);
        self::assertIsString($raw);

        $rejected = 0;
        $attempts = 0;

        // Flip one bit in the nonce, in the tag and in the body in turn.
        foreach ([0, 13, 30] as $offset) {
            if ($offset >= \strlen($raw)) {
                continue;
            }

            ++$attempts;
            $tampered = $raw;
            $tampered[$offset] = \chr(\ord($tampered[$offset]) ^ 0x01);

            try {
                $this->box->open('v2:'.base64_encode($tampered));
            } catch (\RuntimeException) {
                ++$rejected;
            }
        }

        self::assertSame(3, $attempts);
        self::assertSame($attempts, $rejected, 'Every tampered byte position must be rejected.');
    }

    public function testTruncatedCiphertextIsRejected(): void
    {
        $sealed = $this->box->seal('the-real-secret-value');
        $raw = base64_decode(substr($sealed, 3), true);
        self::assertIsString($raw);

        $this->expectException(\RuntimeException::class);
        $this->box->open('v2:'.base64_encode(substr($raw, 0, -1)));
    }

    public function testSealedValuesCarryTheVersionMarker(): void
    {
        self::assertStringStartsWith('v2:', $this->box->seal('anything'));
    }

    /**
     * Values written before the format change have no marker and must keep
     * opening, or the upgrade would silently invalidate every stored secret.
     */
    public function testLegacyCbcCiphertextStillOpens(): void
    {
        $plain = 'legacy-smtp-password';
        $key = hash('sha256', self::SECRET, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, \OPENSSL_RAW_DATA, $iv);
        self::assertIsString($cipher);

        $legacy = base64_encode($iv.$cipher);

        self::assertStringStartsNotWith('v2:', $legacy);
        self::assertSame($plain, $this->box->open($legacy));
    }

    public function testResealingALegacyValueUpgradesItToTheAuthenticatedFormat(): void
    {
        $plain = 'legacy-smtp-password';
        $key = hash('sha256', self::SECRET, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, \OPENSSL_RAW_DATA, $iv);
        self::assertIsString($cipher);

        $upgraded = $this->box->seal($this->box->open(base64_encode($iv.$cipher)));

        self::assertStringStartsWith('v2:', $upgraded);
        self::assertSame($plain, $this->box->open($upgraded));
    }

    public function testLegacyCbcCiphertextFromAnotherSecretIsRejected(): void
    {
        $key = hash('sha256', 'a-completely-different-kernel-secret', true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt('legacy', 'aes-256-cbc', $key, \OPENSSL_RAW_DATA, $iv);
        self::assertIsString($cipher);

        $this->expectException(\RuntimeException::class);
        $this->box->open(base64_encode($iv.$cipher));
    }
}
