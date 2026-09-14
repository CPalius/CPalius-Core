<?php

declare(strict_types=1);

namespace App\Core\Storage\Driver;

use App\Core\Storage\RemoteTargetInterface;
use App\Core\Storage\StorageException;

/**
 * Plain FTP and explicit FTPS, over the ext-ftp extension.
 *
 * FTP is here because it is what shared hosting actually gives people. An
 * operator on a €3/month plan has no object storage and no SSH, but they
 * usually do have a second FTP account somewhere — another hosting package, a
 * NAS at the office — and that is a real off-site destination for backups.
 *
 * Passive mode is the default and is not optional in practice: active mode asks
 * the *server* to open a connection back to the web host, which every firewall
 * written in the last twenty years drops. The setting exists for the one
 * operator whose server is misconfigured the other way.
 *
 * ext-ftp is not universally compiled in, so every entry point checks. Failing
 * with "the FTP extension is not installed" is worth a great deal more than a
 * fatal "call to undefined function ftp_connect()" in a cron log nobody reads.
 */
final class FtpTarget implements RemoteTargetInterface
{
    /** @var list<string> */
    public const FIELDS = ['host', 'port', 'username', 'password', 'path', 'passive', 'ssl', 'timeout'];

    private const DEFAULT_TIMEOUT = 30;

    private readonly string $host;
    private readonly int $port;
    private readonly string $username;
    private readonly string $password;
    private readonly string $basePath;
    private readonly bool $passive;
    private readonly bool $ssl;
    private readonly int $timeout;

    /** @var \FTP\Connection|null Reused across calls within one request; closed by the destructor. */
    private mixed $connection = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $host = trim((string) ($config['host'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));

        if ($host === '' || $username === '') {
            throw new StorageException('aacp.storage.error.ftp_incomplete');
        }

        $port = (int) ($config['port'] ?? 21);
        $timeout = (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);

        $this->host = $host;
        $this->port = $port > 0 && $port <= 65535 ? $port : 21;
        $this->username = $username;
        $this->password = (string) ($config['password'] ?? '');
        $this->basePath = trim(str_replace('\\', '/', (string) ($config['path'] ?? '')), '/');
        // Absent means "yes" — a freshly configured target with no checkbox
        // submitted must not silently pick the mode firewalls block.
        $this->passive = !array_key_exists('passive', $config) || self::truthy($config['passive']);
        $this->ssl = self::truthy($config['ssl'] ?? null);
        $this->timeout = $timeout > 0 && $timeout <= 600 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function type(): string
    {
        return 'ftp';
    }

    public function put(string $localPath, string $remoteKey): void
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new StorageException(sprintf('Local file is not readable: %s', $localPath));
        }

        $remote = $this->pathFor($remoteKey);
        $connection = $this->connect();

        $this->ensureDirectory($connection, dirname($remote));

        // FTP_BINARY always. ASCII mode "helpfully" rewrites line endings,
        // which corrupts every image and every gzip archive that passes
        // through it — and does so silently, so the file only turns out to be
        // broken when someone tries to restore from it.
        if (!@ftp_put($connection, $remote, $localPath, FTP_BINARY)) {
            throw new StorageException(sprintf('Upload failed: %s', $remote));
        }
    }

    public function has(string $remoteKey): bool
    {
        // ftp_size returns -1 both for "absent" and for servers that refuse
        // SIZE on the current transfer type; binary mode is set at connect
        // time, so -1 here means absent.
        return @ftp_size($this->connect(), $this->pathFor($remoteKey)) >= 0;
    }

    public function delete(string $remoteKey): void
    {
        $remote = $this->pathFor($remoteKey);
        $connection = $this->connect();

        if (@ftp_size($connection, $remote) < 0) {
            return;
        }

        if (!@ftp_delete($connection, $remote)) {
            throw new StorageException(sprintf('Delete failed: %s', $remote));
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
                throw new StorageException('The probe file was accepted but is not listed on the server.');
            }

            $this->delete($key);
        } finally {
            @unlink($probe);
        }
    }

    public function describe(): string
    {
        return sprintf('%s://%s%s', $this->ssl ? 'ftps' : 'ftp', $this->host, $this->basePath === '' ? '/' : '/'.$this->basePath);
    }

    public static function isSupported(): bool
    {
        return function_exists('ftp_connect');
    }

    /**
     * @return \FTP\Connection
     */
    private function connect(): mixed
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (!self::isSupported()) {
            throw new StorageException('aacp.storage.error.ftp_extension_missing');
        }

        if ($this->ssl && !function_exists('ftp_ssl_connect')) {
            throw new StorageException('aacp.storage.error.ftp_ssl_missing');
        }

        $connection = $this->ssl
            ? @ftp_ssl_connect($this->host, $this->port, $this->timeout)
            : @ftp_connect($this->host, $this->port, $this->timeout);

        if ($connection === false) {
            throw new StorageException(sprintf('Could not connect to %s:%d', $this->host, $this->port));
        }

        if (!@ftp_login($connection, $this->username, $this->password)) {
            ftp_close($connection);

            throw new StorageException('FTP login was rejected.');
        }

        // Set before any transfer: switching mid-session is what produces the
        // half-binary, half-mangled uploads described in put().
        @ftp_pasv($connection, $this->passive);

        return $this->connection = $connection;
    }

    private function disconnect(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Creates the directory chain leaf-last.
     *
     * Failures are ignored on purpose: MKD on a directory that already exists
     * is an error on most servers, and telling the two apart costs a listing
     * per level. If the directory genuinely could not be made, the ftp_put that
     * follows reports it — from the operation the operator actually asked for.
     *
     * @param \FTP\Connection $connection
     */
    private function ensureDirectory(mixed $connection, string $directory): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');

        if ($directory === '' || $directory === '.') {
            return;
        }

        $current = '';

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $current .= '/'.$segment;

            if (@ftp_chdir($connection, $current)) {
                continue;
            }

            @ftp_mkdir($connection, $current);
        }

        // Every path this class builds is absolute, but a driver that leaves
        // the session parked in a subdirectory is a trap for the next caller.
        @ftp_chdir($connection, '/');
    }

    private function pathFor(string $remoteKey): string
    {
        $key = ltrim(str_replace('\\', '/', $remoteKey), '/');

        if ($key === '' || str_contains($key, '..')) {
            throw new StorageException(sprintf('Refusing an unsafe remote path: "%s".', $remoteKey));
        }

        return '/'.($this->basePath === '' ? $key : $this->basePath.'/'.$key);
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
