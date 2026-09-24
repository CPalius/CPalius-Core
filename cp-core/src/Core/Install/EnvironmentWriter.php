<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Writes the site `.env` from the shipped example, replacing only the keys
 * the wizard owns. Everything else (mailer, redis, messenger) keeps the
 * example default so a shared host without those services still boots.
 */
final class EnvironmentWriter
{
    /**
     * @param array<string, string> $values
     */
    public function write(string $projectDir, array $values): void
    {
        $example = $projectDir.'/.env.example';
        $raw = file_get_contents($example);
        if (!\is_string($raw) || $raw === '') {
            throw new \RuntimeException('.env.example could not be read.');
        }

        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid environment key.');
            }

            $line = $key.'='.$this->quote($value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $raw) === 1) {
                $replaced = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $raw, 1);
                $raw = \is_string($replaced) ? $replaced : $raw;
                continue;
            }

            $raw .= "\n".$line."\n";
        }

        $target = $projectDir.'/.env';
        $tmp = $target.'.installing';
        if (file_put_contents($tmp, $raw, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write .env.');
        }

        if (is_file($target) && !unlink($target)) {
            throw new \RuntimeException('Could not replace .env.');
        }

        if (!rename($tmp, $target)) {
            throw new \RuntimeException('Could not write .env.');
        }
    }

    public static function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $value).'"';
    }
}
