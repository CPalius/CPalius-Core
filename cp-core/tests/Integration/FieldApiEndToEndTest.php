<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Field\Api\FieldValueSerializer;
use App\Core\Field\Display\FieldRenderer;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\FieldValidator;
use App\Core\Field\FieldValuePersister;
use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Field API from definition to output, in one pass.
 *
 * Each stage has its own unit tests; what this one protects is the seam
 * between them. A field defined in the registry has to normalise on the way in,
 * refuse what violates its constraints, survive a round trip through the hybrid
 * JSON column, and then come back out through both the renderer and the
 * serialiser. A defect in any single handoff leaves the others passing.
 */
#[CoversClass(FieldValuePersister::class)]
#[CoversClass(FieldValidator::class)]
final class FieldApiEndToEndTest extends IntegrationTestCase
{
    public function testFieldLifecycle(): void
    {
        $container = $this->container();
        $em = $this->em();

        /** @var FieldTypeRegistry $types */
        $types = $container->get(FieldTypeRegistry::class);

        $subtitle = new FieldDefinition('page', 'subtitle', 'text', 'Subtitle');
        $subtitle->setRequired(true)->setSettings($types->get('text')->normalizeSettings([]));

        $rank = new FieldDefinition('page', 'rank', 'integer', 'Rank');
        $rank->setSettings($types->get('integer')->normalizeSettings([]));

        $em->persist($subtitle);
        $em->persist($rank);
        $em->flush();

        /** @var FieldDefinitionRegistry $registry */
        $registry = $container->get(FieldDefinitionRegistry::class);
        $registry->invalidate();

        self::assertTrue($registry->hasFields('page'));
        self::assertCount(2, $registry->getFieldsForBundle('page'));

        /** @var FieldValidator $validator */
        $validator = $container->get(FieldValidator::class);

        // A required field left empty is refused, and the violation names the
        // field — an unnamed violation cannot be shown next to an input.
        $violations = $validator->validate('page', ['subtitle' => '', 'rank' => '3'], null, 'en');
        self::assertArrayHasKey('subtitle', $violations);

        self::assertSame(
            [],
            $validator->validate('page', ['subtitle' => 'A subtitle', 'rank' => '3'], null, 'en'),
        );

        $node = new Node('Handbook', 'handbook', 'page', 'en');
        $em->persist($node);
        $em->flush();

        /** @var FieldValuePersister $persister */
        $persister = $container->get(FieldValuePersister::class);

        // "3" arrives from an HTML form as a string and must land as an int:
        // normalisation is the reason a JSON column stays queryable.
        self::assertSame([], $persister->persist($node, ['subtitle' => 'A subtitle', 'rank' => '3']));
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Node::class)->findOneBy(['slug' => 'handbook']);
        self::assertInstanceOf(Node::class, $reloaded);

        $data = $reloaded->getFieldableData();
        self::assertSame('A subtitle', $data['subtitle'] ?? null);
        self::assertSame(3, $data['rank'] ?? null, 'the string became an int on the way in');

        // Both output surfaces read the same stored value.
        /** @var FieldRenderer $renderer */
        $renderer = $container->get(FieldRenderer::class);
        /** @var FieldValueSerializer $serializer */
        $serializer = $container->get(FieldValueSerializer::class);

        self::assertTrue($renderer->has($reloaded, 'subtitle'));
        self::assertStringContainsString('A subtitle', $renderer->render($reloaded, 'subtitle'));

        $serialized = $serializer->serialize($reloaded);
        self::assertSame('A subtitle', $serialized['subtitle'] ?? null);
        self::assertSame(3, $serialized['rank'] ?? null);

        // A persist that violates a constraint reports it and writes nothing,
        // rather than storing a half-valid entity.
        $rejected = $persister->persist($reloaded, ['subtitle' => '', 'rank' => '9']);
        self::assertArrayHasKey('subtitle', $rejected);
        self::assertSame('A subtitle', $reloaded->getFieldableData()['subtitle'] ?? null, 'the stored value is untouched');
    }
}
