<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(FieldDefinitionRegistry::class)]
#[CoversClass(FieldDefinition::class)]
final class FieldDefinitionRegistryTest extends TestCase
{
    public function testResultIsCachedAfterFirstRead(): void
    {
        $repository = $this->createMock(FieldDefinitionRepository::class);
        $repository->expects(self::once())
            ->method('findByBundle')
            ->with('page')
            ->willReturn([new FieldDefinition('page', 'subtitle', 'text', 'Subtitle')]);

        $registry = new FieldDefinitionRegistry($repository, new ArrayAdapter());

        $registry->getFieldsForBundle('page');
        $registry->getFieldsForBundle('page');
        $registry->getFieldsForBundle('page');

        self::assertSame('subtitle', $registry->getField('page', 'subtitle')?->getName());
    }

    public function testInvalidateForcesReload(): void
    {
        $repository = $this->createMock(FieldDefinitionRepository::class);
        $repository->method('distinctBundles')->willReturn(['page']);
        $repository->expects(self::exactly(2))
            ->method('findByBundle')
            ->willReturnOnConsecutiveCalls(
                [new FieldDefinition('page', 'a', 'text', 'A')],
                [new FieldDefinition('page', 'a', 'text', 'A'), new FieldDefinition('page', 'b', 'text', 'B')],
            );

        $registry = new FieldDefinitionRegistry($repository, new ArrayAdapter());

        self::assertCount(1, $registry->getFieldsForBundle('page'));
        $registry->invalidate('page');
        self::assertCount(2, $registry->getFieldsForBundle('page'));
    }

    public function testReturnsDetachedDefinitions(): void
    {
        $repository = $this->createMock(FieldDefinitionRepository::class);
        $repository->method('findByBundle')->willReturn([
            (new FieldDefinition('page', 'x', 'text', 'X'))->setQueryable(true)->setCardinality(FieldDefinition::UNLIMITED),
        ]);

        $registry = new FieldDefinitionRegistry($repository, new ArrayAdapter());
        // Second call comes from cache → rebuilt via fromArray().
        $registry->getFieldsForBundle('page');
        $field = $registry->getFieldsForBundle('page')[0];

        self::assertTrue($field->isQueryable());
        self::assertTrue($field->isMultiValue());
        self::assertSame('text', $field->getType());
    }
}
