<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Display;

use App\Core\Display\ViewModeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewModeRegistry::class)]
final class ViewModeRegistryTest extends TestCase
{
    public function testDefaultAlwaysExistsEvenWithoutRegistration(): void
    {
        $registry = new ViewModeRegistry();

        self::assertTrue($registry->has(ViewModeRegistry::DEFAULT));
        self::assertSame('view_mode.label.default', $registry->getLabel(ViewModeRegistry::DEFAULT));
    }

    public function testRegisterAddsAModeVisibleInAllAndIds(): void
    {
        $registry = new ViewModeRegistry();
        $registry->register('teaser', 'view_mode.label.teaser');

        self::assertTrue($registry->has('teaser'));
        self::assertFalse($registry->has('unknown'));
        self::assertSame(['default', 'teaser'], $registry->ids());
        self::assertSame(['default' => 'view_mode.label.default', 'teaser' => 'view_mode.label.teaser'], $registry->all());
    }

    public function testGetLabelReturnsNullForUnknownMode(): void
    {
        self::assertNull((new ViewModeRegistry())->getLabel('nope'));
    }
}
