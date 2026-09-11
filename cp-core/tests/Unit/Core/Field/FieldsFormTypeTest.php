<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Form\FieldsFormType;
use App\Core\Field\Form\FieldWidgetResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(FieldsFormType::class)]
#[CoversClass(FieldWidgetResolver::class)]
final class FieldsFormTypeTest extends TypeTestCase
{
    use FieldTestTrait;

    private bool $granted = true;

    protected function getExtensions(): array
    {
        $registry = $this->fieldDefinitionRegistry([
            $this->definition('page', 'subtitle', 'text'),
            $this->definition('page', 'featured', 'boolean'),
            $this->definition('page', 'tags', 'text', cardinality: 3),
            $this->definition('page', 'secret', 'text'),
        ]);
        // give 'secret' an edit capability so the deny path is exercised
        foreach ($registry->getFieldsForBundle('page') as $def) {
            if ($def->getName() === 'secret') {
                $def->setEditCapability('secret.edit');
            }
        }

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(fn (): bool => $this->granted && false);

        $type = new FieldsFormType($registry, $this->fieldWidgetResolver(), $security);

        return [new PreloadedExtension([$type], [])];
    }

    public function testBuildsAChildPerEditableField(): void
    {
        $form = $this->factory->create(FieldsFormType::class, [], ['bundle' => 'page', 'field_locale' => 'en']);

        self::assertTrue($form->has('subtitle'));
        self::assertTrue($form->has('featured'));
        self::assertTrue($form->has('tags'));
        self::assertFalse($form->has('secret'), 'field with an ungranted edit_capability is omitted');
    }

    public function testSubmitProducesCleanArray(): void
    {
        $form = $this->factory->create(FieldsFormType::class, [], ['bundle' => 'page', 'field_locale' => 'en']);

        $form->submit([
            'subtitle' => 'Hello',
            'featured' => '1',
            'tags' => ['a', 'b'],
        ]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame('Hello', $data['subtitle']);
        self::assertTrue($data['featured']);
        self::assertSame(['a', 'b'], $data['tags']);
    }
}
