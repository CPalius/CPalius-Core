<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Display\FieldRenderer;
use App\Entity\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(FieldRenderer::class)]
final class FieldRendererTest extends TestCase
{
    use FieldTestTrait;

    public function testRenderFormatsAKnownField(): void
    {
        $renderer = $this->renderer([$this->definition('page', 'subtitle', 'text')]);
        $node = $this->pageNode(['subtitle' => 'Hello <world>']);

        self::assertSame('Hello &lt;world&gt;', $renderer->render($node, 'subtitle'));
        self::assertSame('Hello <world>', $renderer->value($node, 'subtitle'));
        self::assertTrue($renderer->has($node, 'subtitle'));
    }

    public function testUndefinedFieldRendersNothing(): void
    {
        $renderer = $this->renderer([$this->definition('page', 'subtitle', 'text')]);
        $node = $this->pageNode(['subtitle' => 'x', 'rogue' => 'not a field']);

        self::assertSame('', $renderer->render($node, 'rogue'));
        self::assertNull($renderer->value($node, 'rogue'));
    }

    public function testViewCapabilityDeniesValueAndRender(): void
    {
        $def = $this->definition('page', 'internal', 'text');
        $def->setViewCapability('page.internal.view');

        $renderer = $this->renderer([$def], granted: false);
        $node = $this->pageNode(['internal' => 'secret']);

        self::assertNull($renderer->value($node, 'internal'));
        self::assertSame('', $renderer->render($node, 'internal'));
    }

    public function testMultiValueRendersAsList(): void
    {
        $renderer = $this->renderer([$this->definition('page', 'tags', 'text', cardinality: -1)]);
        $node = $this->pageNode(['tags' => ['a', 'b']]);

        self::assertSame('<ul class="cp-field-list"><li>a</li><li>b</li></ul>', $renderer->render($node, 'tags'));
    }

    public function testRenderGroupWrapsEachField(): void
    {
        $a = $this->definition('page', 'a', 'text');
        $b = $this->definition('page', 'b', 'text');
        $b->setFieldGroup('extra');

        $out = $this->renderer([$a, $b])->renderGroup($this->pageNode(['a' => '1', 'b' => '2']), 'extra');

        self::assertStringContainsString('cp-field--b', $out);
        self::assertStringNotContainsString('cp-field--a', $out);
    }

    private function pageNode(array $data): Node
    {
        $node = new Node('T', 't', 'page', 'en');
        $node->setData($data);

        return $node;
    }

    /**
     * @param list<\App\Core\Field\Entity\FieldDefinition> $definitions
     */
    private function renderer(array $definitions, bool $granted = true): FieldRenderer
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);

        $references = $this->referenceBatchLoader();
        $fieldDefinitions = $this->fieldDefinitionRegistry($definitions);

        return new FieldRenderer(
            $fieldDefinitions,
            $this->fieldTypeRegistry(),
            $this->formatterResolver($references),
            $references,
            $security,
            $this->entityDisplayRegistry($fieldDefinitions),
        );
    }
}
