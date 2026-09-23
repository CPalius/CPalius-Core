<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Migration\ImportedAccountEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportedAccountEmail::class)]
final class ImportedAccountEmailTest extends TestCase
{
    public function testARealAddressIsKept(): void
    {
        self::assertSame('ali@eski.test', ImportedAccountEmail::resolve('ali@eski.test', 'xenforo', '1'));
    }

    public function testAMissingAddressBecomesAStablePlaceholder(): void
    {
        $email = ImportedAccountEmail::resolve('', 'xenforo', '42');

        self::assertSame('imported-xenforo-42@invalid.invalid', $email);
        self::assertTrue(ImportedAccountEmail::isPlaceholder($email));
    }
}
