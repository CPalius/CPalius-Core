<?php

declare(strict_types=1);

namespace App\Core\Storage\Driver;

/**
 * AWS Signature Version 4 for S3-compatible object storage.
 *
 * Why this exists instead of aws/aws-sdk-php: the SDK is ~90 MB of service
 * definitions for a project whose entire use of S3 is PUT, HEAD and DELETE on
 * one object at a time. CPalius ships as a ZIP that operators upload over FTP
 * to shared hosting — a hundred megabytes of vendor code for three verbs would
 * be felt on every single install, and the signing algorithm below is a page of
 * well-specified hashing that has not changed since 2012.
 *
 * The two S3 quirks that trip up hand-written signers, both handled here:
 *
 *   1. S3 does NOT double-encode the canonical URI. Every other AWS service
 *      URI-encodes the path twice; S3 encodes once. Getting this wrong only
 *      shows up on keys containing characters that need escaping, so it passes
 *      every test with a hash-named file and fails on the first upload with a
 *      space in the name.
 *
 *   2. "/" must survive path encoding. rawurlencode() escapes it to %2F, which
 *      turns one key into one flat object name and signs a path the server
 *      never sees.
 */
final class S3Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const SERVICE = 's3';
    private const TERMINATOR = 'aws4_request';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
    ) {
    }

    /**
     * Headers that authenticate one request, including the ones that had to be
     * signed. Merge the result into the outgoing request verbatim: changing a
     * signed header afterwards invalidates the signature.
     *
     * @param string               $payloadSha256 hex digest of the body, or the literal
     *                                            'UNSIGNED-PAYLOAD' where the body is not hashed
     * @param array<string,string> $extraHeaders  additional headers to sign (canonicalised here)
     *
     * @return array<string, string>
     */
    public function headersFor(
        string $method,
        string $host,
        string $path,
        string $query,
        string $payloadSha256,
        array $extraHeaders = [],
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $shortDate = $now->format('Ymd');

        // Host and the two x-amz headers are always signed; anything the caller
        // adds joins them. Lowercase keys because the canonical form demands it
        // and because it makes the sort below deterministic.
        $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadSha256, 'x-amz-date' => $amzDate];

        foreach ($extraHeaders as $name => $value) {
            $headers[strtolower(trim($name))] = trim($value);
        }

        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            // Sequential whitespace collapses to one space in the canonical
            // form; a header the caller pretty-printed would otherwise sign
            // differently from how the server reads it back.
            $canonicalHeaders .= $name.':'.preg_replace('/\s+/', ' ', $value)."\n";
        }

        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method,
            $this->canonicalUri($path),
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadSha256,
        ]);

        $scope = $shortDate.'/'.$this->region.'/'.self::SERVICE.'/'.self::TERMINATOR;

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $this->signingKey($shortDate), true));

        $headers['Authorization'] = \sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        // 'host' is set by the HTTP client from the URL; sending it explicitly
        // makes some clients emit it twice, which the server reads as a
        // malformed request.
        unset($headers['host']);

        return $headers;
    }

    /**
     * Per-segment encoding that leaves the separators alone (quirk 2 above).
     */
    private function canonicalUri(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * The four-round derivation that scopes a signature to one day, one region
     * and one service — which is what keeps a captured signature from being
     * replayed against a different bucket tomorrow.
     */
    private function signingKey(string $shortDate): string
    {
        $key = hash_hmac('sha256', $shortDate, 'AWS4'.$this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', self::SERVICE, $key, true);

        return hash_hmac('sha256', self::TERMINATOR, $key, true);
    }
}
