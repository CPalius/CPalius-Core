<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Database;

use App\Core\Database\Type\EncryptedStringType;
use App\Core\Security\SecretBox;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncryptedStringType::class)]
final class EncryptedStringTypeTest extends TestCase
{
    private EncryptedStringType $type;
    private MySQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new EncryptedStringType();
        $this->platform = new MySQLPlatform();
        EncryptedStringType::useBox(new SecretBox('unit-test-secret'));
    }

    protected function tearDown(): void
    {
        EncryptedStringType::useBox(null);
    }

    public function testValueIsEncryptedInTheDatabaseAndPlainInPhp(): void
    {
        $stored = $this->type->convertToDatabaseValue('TR330006100519786457841326', $this->platform);

        self::assertNotNull($stored);
        self::assertStringNotContainsString('TR33', $stored);
        self::assertSame('TR330006100519786457841326', $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testNullStaysNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testTheSameValueEncryptsDifferentlyEachTime(): void
    {
        self::assertNotSame(
            $this->type->convertToDatabaseValue('x', $this->platform),
            $this->type->convertToDatabaseValue('x', $this->platform),
        );
    }

    public function testEmptyStringRoundTrips(): void
    {
        $stored = $this->type->convertToDatabaseValue('', $this->platform);

        self::assertSame('', $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testAPlainLegacyValueIsReturnedAsIs(): void
    {
        self::assertSame('plain old value', $this->type->convertToPHPValue('plain old value', $this->platform));
    }

    public function testAWrongKeyFailsLoudlyInsteadOfReturningGarbage(): void
    {
        $stored = $this->type->convertToDatabaseValue('secret', $this->platform);
        EncryptedStringType::useBox(new SecretBox('another-secret'));

        $this->expectException(\RuntimeException::class);

        $this->type->convertToPHPValue($stored, $this->platform);
    }

    public function testColumnIsText(): void
    {
        self::assertStringContainsString('TEXT', strtoupper($this->type->getSQLDeclaration([], $this->platform)));
    }
}
