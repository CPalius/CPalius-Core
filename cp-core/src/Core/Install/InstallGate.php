<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Decides whether a request should boot the application or the web installer.
 *
 * The lock file is written only after a finished wizard run. A site that was
 * installed from the CLI already has a real `.env` and no lock; that file is
 * enough to keep the wizard away, otherwise every existing checkout would
 * greet its owner with an installer on the next request.
 *
 * An in-progress install writes `installing.lock` first. While that file
 * exists, a half-written `.env` must not be treated as "already installed",
 * or a failed run would boot a kernel against an empty database and the
 * operator would have no way back into the wizard.
 */
final class InstallGate
{
    public static function lockFile(string $projectDir): string
    {
        return $projectDir.'/cp-core/var/installed.lock';
    }

    public static function installingFile(string $projectDir): string
    {
        return $projectDir.'/cp-core/var/installing.lock';
    }

    public static function wizardFile(string $projectDir): string
    {
        return $projectDir.'/cp-core/install/web.php';
    }

    public static function shouldBootApplication(string $projectDir): bool
    {
        if (is_file(self::lockFile($projectDir))) {
            return true;
        }

        if (is_file(self::installingFile($projectDir))) {
            return false;
        }

        return self::environmentIsConfigured($projectDir);
    }

    public static function environmentIsConfigured(string $projectDir): bool
    {
        $path = $projectDir.'/.env';
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $raw = file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        $secret = self::envValue($raw, 'APP_SECRET');
        $database = self::envValue($raw, 'DATABASE_URL');
        if ($secret === '' || $database === '') {
            return false;
        }

        // Placeholders shipped in .env.example. A copied example is not a site.
        return !str_contains($database, 'kullanici:parola')
            && !str_contains($database, 'db_user:db_password');
    }

    public static function markInstalling(string $projectDir): void
    {
        self::writeMarker(self::installingFile($projectDir), [
            'started_at' => gmdate('c'),
        ]);
    }

    /**
     * @param array<string, scalar> $meta
     */
    public static function markInstalled(string $projectDir, array $meta): void
    {
        self::writeMarker(self::lockFile($projectDir), $meta);
    }

    public static function clearInstalling(string $projectDir): void
    {
        $path = self::installingFile($projectDir);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param array<string, scalar> $meta
     */
    private static function writeMarker(string $path, array $meta): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create '.$dir);
        }

        $body = "<?php\n\nreturn ".var_export($meta, true).";\n";
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write '.$path);
        }

        if (is_file($path)) {
            unlink($path);
        }

        if (!rename($tmp, $path)) {
            throw new \RuntimeException('Cannot write '.$path);
        }
    }

    private static function envValue(string $raw, string $key): string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $raw, $matches) !== 1) {
            return '';
        }

        return trim($matches[1], " \t\"'");
    }
}
