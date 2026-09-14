<?php

declare(strict_types=1);

namespace App\Core\Storage\Driver;

use App\Core\Storage\RemoteTargetInterface;
use App\Core\Storage\StorageException;
use App\Core\Version\CpVersion;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Any S3-compatible bucket: AWS S3, Cloudflare R2, MinIO, Backblaze B2's S3
 * endpoint, DigitalOcean Spaces.
 *
 * One driver covers all of them because the differences are configuration, not
 * protocol — an endpoint host, a region string, and whether the bucket goes in
 * the hostname or the path. The panel offers "S3" and "R2" as separate choices
 * because operators think of them as separate products; both land here with
 * different defaults.
 *
 * Addressing styles, and why the choice is not cosmetic:
 *
 *   virtual-host  https://bucket.s3.eu-central-1.amazonaws.com/key
 *   path          https://<account>.r2.cloudflarestorage.com/bucket/key
 *
 * AWS has been retiring path-style for years; R2 only speaks path-style. Sign
 * the wrong one and the signature covers a path the server never receives, so
 * the failure surfaces as an opaque SignatureDoesNotMatch rather than a 404.
 */
final class S3Target implements RemoteTargetInterface
{
    /** @var list<string> */
    public const FIELDS = ['endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'prefix', 'path_style'];

    private const TIMEOUT_SECONDS = 120;

    /** Uploading a backup archive is not a 120-second job on a slow link. */
    private const UPLOAD_MAX_DURATION = 1800;

    private readonly S3Signer $signer;
    private readonly string $host;
    private readonly string $bucket;
    private readonly string $prefix;
    private readonly bool $pathStyle;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $type,
        array $config,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKey = trim((string) ($config['access_key'] ?? ''));
        $secretKey = (string) ($config['secret_key'] ?? '');
        $region = trim((string) ($config['region'] ?? ''));
        $region = $region === '' ? 'auto' : $region;
        $endpoint = trim((string) ($config['endpoint'] ?? ''));

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new StorageException('aacp.storage.error.s3_incomplete');
        }

        // An operator who pastes the console URL gets what they meant, rather
        // than a signature computed over "https://host" as a hostname.
        $endpoint = preg_replace('#^https?://#i', '', $endpoint) ?? $endpoint;
        $endpoint = rtrim($endpoint, '/');

        if ($endpoint === '') {
            if ($region === 'auto') {
                // Only R2 uses "auto", and R2 always has an explicit endpoint.
                // Reaching here means neither was configured.
                throw new StorageException('aacp.storage.error.s3_no_endpoint');
            }

            $endpoint = 's3.'.$region.'.amazonaws.com';
        }

        $this->bucket = $bucket;
        $this->prefix = trim(str_replace('\\', '/', (string) ($config['prefix'] ?? '')), '/');
        $this->pathStyle = self::truthy($config['path_style'] ?? null);
        $this->host = $this->pathStyle ? $endpoint : $bucket.'.'.$endpoint;
        $this->signer = new S3Signer($accessKey, $secretKey, $region);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function put(string $localPath, string $remoteKey): void
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new StorageException(sprintf('Local file is not readable: %s', $localPath));
        }

        $digest = hash_file('sha256', $localPath);

        if ($digest === false) {
            throw new StorageException(sprintf('Could not hash local file: %s', $localPath));
        }

        $size = filesize($localPath);
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new StorageException(sprintf('Could not open local file: %s', $localPath));
        }

        try {
            // The body is streamed, never read into a string: a full backup
            // archive is measured in hundreds of megabytes and shared hosting
            // is routinely capped at a 128 MB memory limit.
            $status = $this->send('PUT', $remoteKey, $digest, $stream, [
                'content-type' => $this->contentTypeFor($remoteKey),
                'content-length' => (string) (is_int($size) ? $size : 0),
            ], self::UPLOAD_MAX_DURATION);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new StorageException(sprintf('PUT returned HTTP %d', $status));
        }
    }

    public function has(string $remoteKey): bool
    {
        $status = $this->send('HEAD', $remoteKey, hash('sha256', ''), null, [], self::TIMEOUT_SECONDS);

        if ($status === 404) {
            return false;
        }

        if ($status < 200 || $status >= 300) {
            throw new StorageException(sprintf('HEAD returned HTTP %d', $status));
        }

        return true;
    }

    public function delete(string $remoteKey): void
    {
        $status = $this->send('DELETE', $remoteKey, hash('sha256', ''), null, [], self::TIMEOUT_SECONDS);

        // S3 answers 204 for a successful delete and, by design, also for a key
        // that was never there. A 404 from an S3-compatible implementation that
        // chose otherwise means the same thing.
        if ($status !== 404 && ($status < 200 || $status >= 300)) {
            throw new StorageException(sprintf('DELETE returned HTTP %d', $status));
        }
    }

    public function test(): void
    {
        $key = '.cpalius-probe-'.bin2hex(random_bytes(8));
        $probe = tempnam(sys_get_temp_dir(), 'cpprobe');

        if ($probe === false) {
            throw new StorageException('Could not create a local probe file.');
        }

        file_put_contents($probe, 'CPalius storage probe '.gmdate(DATE_ATOM));

        try {
            $this->put($probe, $key);

            if (!$this->has($key)) {
                throw new StorageException('The probe object was accepted but could not be read back.');
            }

            $this->delete($key);
        } finally {
            @unlink($probe);
        }
    }

    public function describe(): string
    {
        return sprintf('%s://%s%s', $this->type, $this->bucket, $this->prefix === '' ? '' : '/'.$this->prefix);
    }

    /**
     * @param resource|null         $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $remoteKey, string $digest, mixed $body, array $headers, int $maxDuration): int
    {
        $path = $this->pathFor($remoteKey);
        $signed = $this->signer->headersFor($method, $this->host, $path, '', $digest, $headers);

        $options = [
            'headers' => $signed,
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => $maxDuration,
        ];

        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client()->request($method, 'https://'.$this->host.$this->encodePath($path), $options);

            return $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new StorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The unencoded request path, which is also what gets signed. Encoding
     * happens once, on the way out, so signature and wire agree.
     */
    private function pathFor(string $remoteKey): string
    {
        $key = ltrim(str_replace('\\', '/', $remoteKey), '/');

        if ($key === '' || str_contains($key, '..')) {
            throw new StorageException(sprintf('Refusing an unsafe object key: "%s".', $remoteKey));
        }

        if ($this->prefix !== '') {
            $key = $this->prefix.'/'.$key;
        }

        return $this->pathStyle ? '/'.$this->bucket.'/'.$key : '/'.$key;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    private function contentTypeFor(string $remoteKey): string
    {
        return match (strtolower(pathinfo($remoteKey, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'gz' => 'application/gzip',
            default => 'application/octet-stream',
        };
    }

    private function client(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create([
            'headers' => ['User-Agent' => 'CPalius/'.CpVersion::VERSION.' (+https://www.cpalius.com)'],
        ]);
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
