<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Entity;

use App\Core\Entity\FieldableInterface;
use App\Core\Field\FieldValidator;
use App\Core\Field\FieldValueNormalizer;
use App\Core\Field\FieldValuePersister;
use App\Entity\Node;
use App\Entity\User;
use App\Tests\Unit\Core\Field\FieldTestTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The Field API is entity-agnostic: the exact same persister writes custom
 * fields onto a Node and onto a User, driven only by FieldableInterface.
 */
#[CoversNothing]
final class FieldableEntityContractTest extends TestCase
{
    use FieldTestTrait;

    public function testNodeAndUserBothSatisfyTheContract(): void
    {
        $node = new Node('T', 't', 'page', 'en');
        self::assertInstanceOf(FieldableInterface::class, $node);
        self::assertSame('node', $node->fieldableEntityTypeId());
        self::assertSame('page', $node->fieldableBundle());
        self::assertSame('en', $node->fieldableLocale());

        $user = new User('a@b.com');
        self::assertInstanceOf(FieldableInterface::class, $user);
        self::assertSame('user', $user->fieldableEntityTypeId());
        self::assertSame('user', $user->fieldableBundle());
        self::assertSame('und', $user->fieldableLocale());
    }

    public function testPersisterWritesCustomFieldOntoUserAndKeepsProfileKeys(): void
    {
        $registry = $this->fieldDefinitionRegistry([
            $this->definition('user', 'department', 'text'),
            $this->definition('user', 'website', 'url'),
        ]);
        $persister = new FieldValuePersister(
            $registry,
            $this->fieldTypeRegistry(),
            new FieldValueNormalizer($registry, $this->fieldTypeRegistry()),
            new FieldValidator($registry, $this->fieldTypeRegistry()),
        );

        $user = new User('a@b.com');
        $user->setFirstName('Ada');

        $errors = $persister->persist($user, [
            'department' => '<b>Engineering</b>',
            'website' => 'https://example.com/ada',
            'roles' => ['ROLE_SUPERADMIN'], // not a field → must be ignored (Law 5.3)
        ]);

        self::assertSame([], $errors);
        self::assertSame('Ada', $user->getFirstName(), 'profile keys survive');
        self::assertSame('Engineering', $user->getDataValue('department'), 'rich markup stripped for text field');
        self::assertSame('https://example.com/ada', $user->getDataValue('website'));
        self::assertArrayNotHasKey('roles', $user->getFieldableData(), 'non-field request keys never merged');
    }

    public function testUserFieldValidationBlocksBadInput(): void
    {
        $registry = $this->fieldDefinitionRegistry([
            $this->definition('user', 'department', 'text', required: true),
        ]);
        $persister = new FieldValuePersister(
            $registry,
            $this->fieldTypeRegistry(),
            new FieldValueNormalizer($registry, $this->fieldTypeRegistry()),
            new FieldValidator($registry, $this->fieldTypeRegistry()),
        );

        $user = new User('a@b.com');
        $errors = $persister->persist($user, ['department' => '']);

        self::assertArrayHasKey('department', $errors);
        self::assertArrayNotHasKey('department', $user->getFieldableData());
    }
}
