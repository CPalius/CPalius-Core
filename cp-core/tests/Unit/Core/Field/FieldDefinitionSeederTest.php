<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldDefinitionSeeder;
use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldDefinitionSeeder::class)]
final class FieldDefinitionSeederTest extends TestCase
{
    use FieldTestTrait;

    public function testCreatesMissingFieldsAndInvalidatesBundle(): void
    {
        $repository = $this->createMock(FieldDefinitionRepository::class);
        $repository->method('findOneByBundleAndName')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void { $persisted[] = $e; });
        $em->expects(self::once())->method('flush');

        $registry = $this->createMock(FieldDefinitionRegistry::class);
        $registry->expects(self::once())->method('invalidate')->with('page');

        $seeder = new FieldDefinitionSeeder($repository, $em, $this->fieldTypeRegistry(), $registry);

        $created = $seeder->ensure([
            ['bundle' => 'page', 'name' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle', 'weight' => 1],
            ['bundle' => 'page', 'name' => 'bad name', 'type' => 'text'],  // invalid name -> skipped
            ['bundle' => 'page', 'name' => 'weird', 'type' => 'no_such_type'], // invalid type -> skipped
        ]);

        self::assertSame(1, $created);
        self::assertCount(1, $persisted);
        self::assertInstanceOf(FieldDefinition::class, $persisted[0]);
        self::assertSame('subtitle', $persisted[0]->getName());
    }

    public function testNeverClobbersAnExistingField(): void
    {
        $existing = new FieldDefinition('page', 'subtitle', 'text', 'Editor tuned this');
        $repository = $this->createMock(FieldDefinitionRepository::class);
        $repository->method('findOneByBundleAndName')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $registry = $this->createMock(FieldDefinitionRegistry::class);
        $registry->expects(self::never())->method('invalidate');

        $seeder = new FieldDefinitionSeeder($repository, $em, $this->fieldTypeRegistry(), $registry);

        self::assertSame(0, $seeder->ensure([
            ['bundle' => 'page', 'name' => 'subtitle', 'type' => 'text', 'label' => 'Module default'],
        ]));
        self::assertSame('Editor tuned this', $existing->getLabel());
    }
}
