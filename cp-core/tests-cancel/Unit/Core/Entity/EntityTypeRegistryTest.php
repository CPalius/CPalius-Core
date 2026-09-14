<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Entity;

use App\Core\Entity\EntityTypeDefinition;
use App\Core\Entity\EntityTypeRegistry;
use App\Entity\Node;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTypeRegistry::class)]
#[CoversClass(EntityTypeDefinition::class)]
final class EntityTypeRegistryTest extends TestCase
{
    private function registry(): EntityTypeRegistry
    {
        return new EntityTypeRegistry([
            'node' => [
                'id' => 'node',
                'className' => Node::class,
                'label' => 'entity.type.node',
                'fieldable' => true,
                'bundleable' => true,
                'revisionable' => true,
                'translatable' => true,
            ],
            'user' => [
                'id' => 'user',
                'className' => User::class,
                'label' => 'entity.type.user',
                'fieldable' => true,
                'bundleable' => false,
                'revisionable' => false,
                'translatable' => false,
            ],
            'report' => [
                'id' => 'report',
                'className' => 'Modules\\Demo\\Entity\\Report',
                'label' => 'report',
                'fieldable' => false,
                'bundleable' => false,
                'revisionable' => false,
                'translatable' => false,
            ],
        ]);
    }

    public function testLookupByIdAndClass(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->has('node'));
        self::assertFalse($registry->has('missing'));
        self::assertSame('node', $registry->get('node')->id);
        self::assertNull($registry->tryGet('missing'));
        self::assertSame('user', $registry->forClass(User::class)?->id);
        self::assertSame('user', $registry->forClass('\\'.User::class)?->id);
        self::assertNull($registry->forClass('App\\Entity\\Category'));
    }

    public function testGetThrowsForUnknownId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->get('nope');
    }

    public function testFieldableFilterExcludesNonFieldableTypes(): void
    {
        $fieldable = $this->registry()->fieldable();

        self::assertArrayHasKey('node', $fieldable);
        self::assertArrayHasKey('user', $fieldable);
        self::assertArrayNotHasKey('report', $fieldable);
    }

    public function testDefinitionsAreSortedById(): void
    {
        self::assertSame(['node', 'report', 'user'], array_keys($this->registry()->all()));
    }

    public function testDefaultBundleEqualsId(): void
    {
        self::assertSame('user', $this->registry()->get('user')->defaultBundle());
    }
}
