<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\ReferenceTargetResolver;
use App\Core\Field\Type\EntityReferenceFieldType;
use App\Core\Resource\ResourceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityReferenceFieldType::class)]
#[CoversClass(ReferenceTargetResolver::class)]
final class EntityReferenceFieldTypeTest extends TestCase
{
    use FieldTestTrait;

    public function testNormalizeCoercesToPositiveIntOrNull(): void
    {
        $type = new EntityReferenceFieldType($this->referenceResolver());
        $ctx = $this->context($this->definition('page', 'author', 'reference', settings: ['target' => 'user']));

        self::assertSame(42, $type->normalize('42', $ctx));
        self::assertNull($type->normalize('0', $ctx));
        self::assertNull($type->normalize(-5, $ctx));
        self::assertNull($type->normalize('abc', $ctx));
        self::assertNull($type->normalize(['id' => 1], $ctx));
    }

    public function testValidateRejectsUnknownTarget(): void
    {
        $type = new EntityReferenceFieldType($this->referenceResolver());
        $ctx = $this->context($this->definition('page', 'author', 'reference', settings: ['target' => 'evil:System']));

        self::assertSame(['field.violation.reference_target_invalid'], $type->validate(1, $ctx));
    }

    public function testValidateChecksExistence(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class, int $id): ?object => $id === 7 ? new \stdClass() : null);
        $resolver = new ReferenceTargetResolver($em, new ResourceRegistry(), $this->vocabularyRegistry());
        $type = new EntityReferenceFieldType($resolver);
        $ctx = $this->context($this->definition('page', 'author', 'reference', settings: ['target' => 'user']));

        self::assertSame([], $type->validate(7, $ctx));
        self::assertSame(['field.violation.reference_missing'], $type->validate(9, $ctx));
    }

    public function testNormalizeSettingsFallsBackToNode(): void
    {
        $type = new EntityReferenceFieldType($this->referenceResolver());

        self::assertSame(['target' => 'user'], $type->normalizeSettings(['target' => 'USER']));
        self::assertSame(['target' => 'node'], $type->normalizeSettings(['target' => 'garbage']));
    }
}
