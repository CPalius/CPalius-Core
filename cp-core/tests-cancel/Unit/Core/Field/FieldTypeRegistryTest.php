<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Type\TextFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldTypeRegistry::class)]
final class FieldTypeRegistryTest extends TestCase
{
    use FieldTestTrait;

    public function testAllCoreTypesAreRegistered(): void
    {
        $registry = $this->fieldTypeRegistry();

        foreach (['text', 'textarea', 'rich_text', 'boolean', 'integer', 'decimal', 'email', 'url', 'date', 'datetime', 'select', 'reference', 'image', 'file'] as $id) {
            self::assertTrue($registry->has($id), "missing field type: $id");
            self::assertSame($id, $registry->get($id)::id());
        }

        self::assertCount(14, $registry->ids());
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fieldTypeRegistry()->get('nope');
    }

    public function testChoicesMapsIdToLabelKey(): void
    {
        $choices = $this->fieldTypeRegistry()->choices();

        self::assertSame((new TextFieldType())->label(), $choices['text']);
    }
}
