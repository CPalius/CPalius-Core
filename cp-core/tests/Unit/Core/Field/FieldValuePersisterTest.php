<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\FieldValidator;
use App\Core\Field\FieldValueNormalizer;
use App\Core\Field\FieldValuePersister;
use App\Entity\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldValuePersister::class)]
final class FieldValuePersisterTest extends TestCase
{
    use FieldTestTrait;

    public function testMergesCleanValuesAndKeepsNonFieldKeys(): void
    {
        $registry = $this->fieldDefinitionRegistry([
            $this->definition('page', 'subtitle', 'text'),
        ]);
        $persister = $this->persister($registry);

        $node = new Node('T', 't', 'page', 'en');
        $node->setData(['seo' => ['title' => 'keep me'], 'subtitle' => 'old']);

        $errors = $persister->persist($node, ['subtitle' => '<b>New</b>']);

        self::assertSame([], $errors);
        self::assertSame(['seo' => ['title' => 'keep me'], 'subtitle' => 'New'], $node->getData());
    }

    public function testValidationErrorsBlockPersistence(): void
    {
        $registry = $this->fieldDefinitionRegistry([
            $this->definition('page', 'subtitle', 'text', required: true),
        ]);
        $persister = $this->persister($registry);

        $node = new Node('T', 't', 'page', 'en');
        $node->setData(['subtitle' => 'existing']);

        $errors = $persister->persist($node, ['subtitle' => '']);

        self::assertSame(['subtitle' => ['field.violation.required']], $errors);
        self::assertSame(['subtitle' => 'existing'], $node->getData(), 'data untouched on error');
    }

    public function testEmptySubmittedFieldClearsStoredValue(): void
    {
        $registry = $this->fieldDefinitionRegistry([
            $this->definition('page', 'subtitle', 'text'),
        ]);
        $persister = $this->persister($registry);

        $node = new Node('T', 't', 'page', 'en');
        $node->setData(['subtitle' => 'to be cleared']);

        $persister->persist($node, ['subtitle' => '']);

        self::assertArrayNotHasKey('subtitle', $node->getData());
    }

    private function persister(\App\Core\Field\FieldDefinitionRegistry $registry): FieldValuePersister
    {
        $types = $this->fieldTypeRegistry();

        return new FieldValuePersister(
            $registry,
            $types,
            new FieldValueNormalizer($registry, $types),
            new FieldValidator($registry, $types),
        );
    }
}
