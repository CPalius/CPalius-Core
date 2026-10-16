<?php

declare(strict_types=1);

namespace App\Core\Database\Type;

use App\Core\Security\SecretBox;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * A text column whose value is encrypted in the database (AES-256-GCM through SecretBox) and plain in PHP.
 *
 *     #[ORM\Column(type: 'cp_encrypted', nullable: true)]
 *     #[CpField(sensitive: true, list: false)]
 *     private ?string $nationalId = null;
 *
 * A database dump, a backup file or a read-only SQL injection then yields ciphertext, not national ids or IBANs.
 *
 * Things to know before using it:
 *  - The key is derived from APP_SECRET. Lose APP_SECRET and every encrypted value is unreadable, so keep it in
 *    your secrets store like a database password, and restore backups together with it. To change it on purpose,
 *    move the old value to APP_SECRET_PREVIOUS and run cp:secrets:rotate.
 *  - The ciphertext differs on every write, so the column cannot be searched, sorted, indexed or made unique.
 *    Keep a separate blind index (a keyed hash) if you must look a value up.
 *  - A value that is not in the encrypted format is returned as it is. That lets you switch an existing plain
 *    column to this type and have rows encrypt themselves as they are next saved.
 *  - It is opt-in per column. Nothing in the core uses it unless a module declares the type.
 */
final class EncryptedStringType extends Type
{
    public const NAME = 'cp_encrypted';

    /** Formats SecretBox writes: v3 with a key id (current) and v2 (read-only, rewritten on rotation). */
    private const PREFIXES = ['v3:', 'v2:'];

    private static ?SecretBox $box = null;

    /** For tests, and for hosts that keep the secret somewhere other than APP_SECRET. */
    public static function useBox(?SecretBox $box): void
    {
        self::$box = $box;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : self::box()->seal((string) $value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $stored = (string) $value;

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($stored, $prefix)) {
                return self::box()->open($stored);
            }
        }

        return $stored;
    }

    private static function box(): SecretBox
    {
        if (self::$box !== null) {
            return self::$box;
        }

        $secret = (string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: '');

        if ($secret === '') {
            // Never fall back to a built-in key: data "encrypted" with a public default is worse than an error.
            throw new \LogicException('APP_SECRET is not set, so encrypted columns cannot be read or written.');
        }

        return self::$box = new SecretBox($secret, (string) ($_SERVER['APP_SECRET_PREVIOUS'] ?? $_ENV['APP_SECRET_PREVIOUS'] ?? getenv('APP_SECRET_PREVIOUS') ?: ''));
    }
}
