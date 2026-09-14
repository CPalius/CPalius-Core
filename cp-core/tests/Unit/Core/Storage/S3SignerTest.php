<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Storage;

use App\Core\Storage\Driver\S3Signer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * SigV4 is hand-written here rather than pulled in with 90 MB of AWS SDK, so
 * these tests carry the weight that the SDK's own test suite would otherwise.
 *
 * A note on what they can and cannot prove. There is no AWS endpoint in this
 * test run, so nothing here demonstrates that Amazon would accept the
 * signature. What the golden value below IS: the output of a second
 * implementation, written separately from S3Signer straight off the AWS
 * specification text, that agrees with it byte for byte — canonical request,
 * string to sign and final HMAC. Two independent implementations agreeing is
 * real evidence about the algorithm; it is still not evidence about a
 * particular bucket's policy, which is what the live write-probe in
 * StorageTargetRegistry::test() exists for, and why the panel refuses to use a
 * target that has not passed one.
 */
#[CoversClass(S3Signer::class)]
final class S3SignerTest extends TestCase
{
    private const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private function signer(string $region = 'us-east-1'): S3Signer
    {
        return new S3Signer(self::ACCESS_KEY, self::SECRET_KEY, $region);
    }

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-14 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testProducesTheExpectedHeaderSet(): void
    {
        $headers = $this->signer()->headersFor(
            'PUT',
            'bucket.s3.us-east-1.amazonaws.com',
            '/2026/09/photo.jpg',
            '',
            hash('sha256', 'body'),
            [],
            $this->at(),
        );

        self::assertSame('20260914T120000Z', $headers['x-amz-date']);
        self::assertSame(hash('sha256', 'body'), $headers['x-amz-content-sha256']);

        // Host is signed but must not be sent: several clients set it from the
        // URL themselves, and a duplicate Host header reads as a malformed
        // request.
        self::assertArrayNotHasKey('host', $headers);

        self::assertStringStartsWith('AWS4-HMAC-SHA256 ', $headers['Authorization']);
        self::assertStringContainsString(
            'Credential='.self::ACCESS_KEY.'/20260914/us-east-1/s3/aws4_request',
            $headers['Authorization'],
        );
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-content-sha256;x-amz-date',
            $headers['Authorization'],
        );
        self::assertMatchesRegularExpression('/Signature=[a-f0-9]{64}$/', $headers['Authorization']);
    }

    /**
     * Regression pin. See the class docblock for what this does and does not
     * establish.
     */
    public function testSignatureIsStableForAFixedRequest(): void
    {
        $headers = $this->signer()->headersFor(
            'PUT',
            'bucket.s3.us-east-1.amazonaws.com',
            '/2026/09/photo.jpg',
            '',
            hash('sha256', 'body'),
            [],
            $this->at(),
        );

        preg_match('/Signature=([a-f0-9]{64})/', $headers['Authorization'], $m);

        self::assertSame(
            'c33d6bc75252bb86dda758163b496e7c316512b483e7248652fe8677b24233ef',
            $m[1],
            'the signing algorithm changed; if that was deliberate, re-probe a real bucket before updating this pin',
        );
    }

    /**
     * Quirk 2 from S3Signer: rawurlencode() escapes "/" to %2F, which would
     * sign one flat object name instead of a path — and the server would then
     * compute a different signature for the request it actually received.
     */
    public function testPathSeparatorsSurviveCanonicalisation(): void
    {
        $withSlashes = $this->signature('/a/b/c.jpg');
        $flattened = $this->signature('/a%2Fb%2Fc.jpg');

        self::assertNotSame($flattened, $withSlashes, 'slashes must not be escaped into the object name');
    }

    /**
     * Keys with characters that need escaping are the ones that break a naive
     * signer, because a hash-named upload never exercises the path.
     */
    public function testEncodesCharactersThatNeedIt(): void
    {
        // Different unencoded paths must produce different signatures; if the
        // canonical URI silently dropped or mangled the space, these two would
        // collide.
        self::assertNotSame($this->signature('/a b.jpg'), $this->signature('/ab.jpg'));
        self::assertNotSame($this->signature('/a+b.jpg'), $this->signature('/a b.jpg'));
    }

    public function testScopeIsBoundToRegionAndDay(): void
    {
        $frankfurt = $this->signature('/x.jpg', region: 'eu-central-1');
        $virginia = $this->signature('/x.jpg', region: 'us-east-1');

        // A signature that did not depend on the region could be replayed
        // against a bucket in another one.
        self::assertNotSame($frankfurt, $virginia);

        $tomorrow = $this->signature('/x.jpg', at: new \DateTimeImmutable('2026-09-15 12:00:00', new \DateTimeZone('UTC')));

        self::assertNotSame($virginia, $tomorrow);
    }

    public function testExtraHeadersAreSignedAndCanonicalised(): void
    {
        $headers = $this->signer()->headersFor(
            'PUT',
            'bucket.s3.us-east-1.amazonaws.com',
            '/x.jpg',
            '',
            hash('sha256', ''),
            ['Content-Type' => 'image/jpeg'],
            $this->at(),
        );

        self::assertStringContainsString(
            'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date',
            $headers['Authorization'],
        );
        self::assertSame('image/jpeg', $headers['content-type']);
    }

    public function testADifferentSecretProducesADifferentSignature(): void
    {
        $a = (new S3Signer(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1'))
            ->headersFor('PUT', 'h', '/x', '', hash('sha256', ''), [], $this->at());
        $b = (new S3Signer(self::ACCESS_KEY, 'another-secret', 'us-east-1'))
            ->headersFor('PUT', 'h', '/x', '', hash('sha256', ''), [], $this->at());

        self::assertNotSame($a['Authorization'], $b['Authorization']);
    }

    private function signature(string $path, string $region = 'us-east-1', ?\DateTimeImmutable $at = null): string
    {
        $headers = $this->signer($region)->headersFor(
            'PUT',
            'bucket.s3.'.$region.'.amazonaws.com',
            $path,
            '',
            hash('sha256', ''),
            [],
            $at ?? $this->at(),
        );

        preg_match('/Signature=([a-f0-9]{64})/', $headers['Authorization'], $m);

        return $m[1];
    }
}
