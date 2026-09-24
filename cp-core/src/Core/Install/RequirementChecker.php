<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Shared-hosting preflight. The wizard refuses to write `.env` until every
 * hard requirement passes, because a missing extension fails later inside a
 * half-built container that the operator cannot repair without SSH.
 *
 * @phpstan-type Requirement array{id: string, ok: bool, severity: 'ok'|'warn'|'fail', label: string, detail: string}
 */
final class RequirementChecker
{
    private const EXTENSIONS = [
        'ctype',
        'iconv',
        'fileinfo',
        'pdo',
        'pdo_mysql',
        'mbstring',
        'xml',
        'dom',
        'tokenizer',
        'json',
        'intl',
        'openssl',
    ];

    /**
     * @return list<Requirement>
     */
    public function check(string $projectDir): array
    {
        $checks = [];

        $phpOk = PHP_VERSION_ID >= 80400;
        $checks[] = $this->row('php', $phpOk, 'fail', 'PHP 8.4+', PHP_VERSION);

        foreach (self::EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->row('ext.'.$extension, $loaded, 'fail', 'ext-'.$extension, $loaded ? 'loaded' : 'missing');
        }

        $memory = $this->memoryBytes((string) ini_get('memory_limit'));
        $memoryOk = $memory < 0 || $memory >= 128 * 1024 * 1024;
        $memoryWarn = $memory >= 0 && $memory < 256 * 1024 * 1024;
        $checks[] = $this->row(
            'memory',
            $memoryOk,
            $memoryOk && $memoryWarn ? 'warn' : ($memoryOk ? 'ok' : 'fail'),
            'memory_limit',
            (string) ini_get('memory_limit'),
        );

        $checks[] = $this->row(
            'vendor',
            is_file($projectDir.'/cp-includes/vendor/autoload.php'),
            'fail',
            'cp-includes/vendor',
            is_file($projectDir.'/cp-includes/vendor/autoload.php') ? 'present' : 'missing — this package must include vendor',
        );

        $checks[] = $this->row(
            'env-example',
            is_file($projectDir.'/.env.example'),
            'fail',
            '.env.example',
            is_file($projectDir.'/.env.example') ? 'present' : 'missing',
        );

        foreach ([
            'env' => $projectDir,
            'var' => $projectDir.'/cp-core/var',
            'uploads' => $projectDir.'/public/uploads',
        ] as $id => $dir) {
            $writable = $this->isWritableDir($dir);
            $checks[] = $this->row($id, $writable, 'fail', $dir, $writable ? 'writable' : 'not writable');
        }

        return $checks;
    }

    /**
     * @param list<Requirement> $checks
     */
    public function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['severity'] === 'fail' || $check['ok'] === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Requirement
     */
    private function row(string $id, bool $ok, string $severity, string $label, string $detail): array
    {
        if (!$ok) {
            $severity = 'fail';
        }

        return [
            'id' => $id,
            'ok' => $ok,
            'severity' => $ok ? ($severity === 'fail' ? 'ok' : $severity) : 'fail',
            'label' => $label,
            'detail' => $detail,
        ];
    }

    private function isWritableDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent = \dirname($dir);

        return is_dir($parent) && is_writable($parent);
    }

    private function memoryBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
