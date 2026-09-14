<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldValidator::class)]
final class FieldValidatorTest extends TestCase
{
    use FieldTestTrait;

    public function testRequiredFieldMissing(): void
    {
        $validator = $this->validator([$this->definition('page', 'subtitle', 'text', required: true)]);

        $errors = $validator->validate('page', [], null, 'en');

        self::assertSame(['subtitle' => ['field.violation.required']], $errors);
    }

    public function testValidValuesPass(): void
    {
        $validator = $this->validator([
            $this->definition('page', 'subtitle', 'text', required: true),
            $this->definition('page', 'mail', 'email'),
        ]);

        $errors = $validator->validate('page', ['subtitle' => 'Hi', 'mail' => 'a@b.com'], null, 'en');

        self::assertSame([], $errors);
    }

    public function testPerTypeViolationBubblesUp(): void
    {
        $validator = $this->validator([$this->definition('page', 'n', 'integer', settings: ['min' => 10, 'max' => 20])]);

        $errors = $validator->validate('page', ['n' => 3], null, 'en');

        self::assertSame(['n' => ['field.violation.too_small']], $errors);
    }

    public function testTooManyValues(): void
    {
        $validator = $this->validator([$this->definition('page', 'tags', 'text', cardinality: 2)]);

        $errors = $validator->validate('page', ['tags' => ['a', 'b', 'c']], null, 'en');

        self::assertSame(['tags' => ['field.violation.too_many_values']], $errors);
    }

    public function testUnlimitedCardinalityNeverTriggersTooMany(): void
    {
        $validator = $this->validator([$this->definition('page', 'tags', 'text', cardinality: FieldDefinition::UNLIMITED)]);

        $errors = $validator->validate('page', ['tags' => array_fill(0, 50, 'x')], null, 'en');

        self::assertSame([], $errors);
    }

    /**
     * @param list<FieldDefinition> $definitions
     */
    private function validator(array $definitions): FieldValidator
    {
        return new FieldValidator(
            $this->fieldDefinitionRegistry($definitions),
            $this->fieldTypeRegistry(),
        );
    }
}
