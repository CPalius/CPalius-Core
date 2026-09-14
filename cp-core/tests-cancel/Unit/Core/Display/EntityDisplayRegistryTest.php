<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Display;

use App\Core\Display\Entity\EntityDisplay;
use App\Core\Display\EntityDisplayRegistry;
use App\Core\Display\Repository\EntityDisplayRepository;
use App\Core\Display\ViewModeRegistry;
use App\Tests\Unit\Core\Field\FieldTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(EntityDisplayRegistry::class)]
final class EntityDisplayRegistryTest extends TestCase
{
    use FieldTestTrait;

    public function testUnconfiguredBundleShowsEveryFieldWithItsOwnWeight(): void
    {
        $fieldDefinitions = $this->fieldDefinitionRegistry([
            $this->definition('page', 'a', 'text')->setWeight(5),
            $this->definition('page', 'b', 'text')->setWeight(1),
        ]);
        $repository = $this->createMock(EntityDisplayRepository::class);
        $repository->method('findByBundleAndViewMode')->willReturn([]);

        $registry = new EntityDisplayRegistry($repository, $fieldDefinitions, new ViewModeRegistry(), new ArrayAdapter());

        $rows = $registry->resolve('page', ViewModeRegistry::DEFAULT);
        self::assertCount(2, $rows);
        self::assertTrue($rows[0]->visible && $rows[1]->visible);
        // Sorted by weight: "b" (1) before "a" (5).
        self::assertSame(['b', 'a'], array_map(static fn ($r) => $r->definition->getName(), $rows));
    }

    public function testExplicitOverrideHidesAndReordersOnlyInItsViewMode(): void
    {
        $fieldDefinitions = $this->fieldDefinitionRegistry([
            $this->definition('page', 'a', 'text'),
            $this->definition('page', 'b', 'text'),
        ]);

        $hideA = new EntityDisplay('page', 'teaser', 'a');
        $hideA->setVisible(false);

        $repository = $this->createMock(EntityDisplayRepository::class);
        $repository->method('findByBundleAndViewMode')->willReturnCallback(
            static fn (string $bundle, string $viewMode): array => $viewMode === 'teaser' ? [$hideA] : [],
        );

        $viewModes = new ViewModeRegistry();
        $viewModes->register('teaser', 'view_mode.label.teaser');

        $registry = new EntityDisplayRegistry($repository, $fieldDefinitions, $viewModes, new ArrayAdapter());

        self::assertSame(['b'], array_map(static fn ($r) => $r->definition->getName(), $registry->visibleFields('page', 'teaser')));
        self::assertSame(['a', 'b'], array_map(static fn ($r) => $r->definition->getName(), $registry->visibleFields('page', ViewModeRegistry::DEFAULT)));
    }

    public function testUnknownViewModeFallsBackToDefault(): void
    {
        $fieldDefinitions = $this->fieldDefinitionRegistry([$this->definition('page', 'a', 'text')]);
        $repository = $this->createMock(EntityDisplayRepository::class);
        $repository->method('findByBundleAndViewMode')->willReturn([]);

        $registry = new EntityDisplayRegistry($repository, $fieldDefinitions, new ViewModeRegistry(), new ArrayAdapter());

        self::assertCount(1, $registry->resolve('page', 'no-such-view-mode'));
    }
}
