<?php

declare(strict_types=1);

namespace Modules\Media\Service;

use App\Core\Media\MimeTypeAllowlist;
use App\Core\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;

/**
 * What the media library accepts: which file types and how large. One JSON value in cp_settings.
 *
 * Until an administrator saves the screen, the library accepts exactly what the core allowlist
 * accepts (images, PDF, video) with no cap beyond the server's own. Saved choices narrow or widen
 * that within MimeTypeAllowlist::catalog() only; the server's PHP limits stay a hard ceiling.
 */
final class MediaUploadSettings
{
    public const KEY = 'media.upload';

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly Connection $connection,
        private readonly MimeTypeAllowlist $core,
    ) {
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $stored = $this->stored();
        $known = array_merge(...array_values(MimeTypeAllowlist::catalog()));
        $chosen = \is_array($stored['allowed'] ?? null) ? $stored['allowed'] : MimeTypeAllowlist::coreExtensions();

        return array_values(array_intersect($known, array_map('strval', $chosen)));
    }

    /** Megabytes the administrator chose; 0 = "whatever the server allows". */
    public function maxMegabytes(): int
    {
        return max(0, (int) ($this->stored()['max_mb'] ?? 0));
    }

    /** The cap actually applied: the chosen size, never above what PHP will accept. */
    public function maxBytes(): int
    {
        $server = self::serverLimitBytes();
        $chosen = $this->maxMegabytes() * 1024 * 1024;

        return $chosen > 0 ? ($server > 0 ? min($chosen, $server) : $chosen) : $server;
    }

    /** upload_max_filesize and post_max_size, whichever is smaller; 0 when unlimited. */
    public static function serverLimitBytes(): int
    {
        $limits = array_filter([self::bytes((string) \ini_get('upload_max_filesize')), self::bytes((string) \ini_get('post_max_size'))], static fn (int $b): bool => $b > 0);

        return $limits === [] ? 0 : min($limits);
    }

    public function allowlist(): MimeTypeAllowlist
    {
        return $this->core->restrictedTo($this->allowedExtensions());
    }

    /**
     * @param list<string> $extensions
     */
    public function save(array $extensions, int $maxMegabytes): void
    {
        $known = array_merge(...array_values(MimeTypeAllowlist::catalog()));
        $json = json_encode([
            'allowed' => array_values(array_intersect($known, array_map(static fn (mixed $e): string => strtolower((string) $e), $extensions))),
            'max_mb' => max(0, min(100000, $maxMegabytes)),
        ], \JSON_THROW_ON_ERROR);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $updated = $this->connection->executeStatement(
            'UPDATE cp_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
            [$json, $now, self::KEY],
        );
        if ($updated === 0 && $this->connection->fetchOne('SELECT 1 FROM cp_settings WHERE setting_key = ?', [self::KEY]) === false) {
            $this->connection->executeStatement(
                'INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (?, ?, ?, ?)',
                [self::KEY, $json, 'media', $now],
            );
        }
        $this->registry->clearCache(self::KEY);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $decoded = json_decode((string) $this->registry->getRaw(self::KEY), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
