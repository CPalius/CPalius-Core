<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldValueNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Manifesto Law 5.3: this is the mass-assignment choke point.
 */
#[CoversClass(FieldValueNormalizer::class)]
final class FieldValueNormalizerTest extends TestCase
{
    use FieldTestTrait;

    public function testOnlyDefinedFieldsAreWritten(): void
    {
        $normalizer = $this->normalizer([
            $this->definition('page', 'subtitle', 'text'),
        ]);

        $out = $normalizer->normalize('page', [
            'subtitle' => 'Hello',
            'is_admin' => true,          // not a field — must be dropped
            'status' => 'published',     // not a field — must be dropped
            'data' => ['evil' => 1],     // not a field — must be dropped
        ], null, 'en');

        self::assertSame(['subtitle' => 'Hello'], $out);
    }

    public function testRichTextIsSanitizedOnWrite(): void
    {
        $normalizer = $this->normalizer([$this->definition('page', 'body', 'rich_text')]);

        $out = $normalizer->normalize('page', [
            'body' => '<p>ok</p><script>alert(1)</script>',
        ], null, 'en');

        self::assertArrayHasKey('body', $out);
        self::assertIsArray($out['body']);
        self::assertStringNotContainsString('<script', $out['body']['value']);
    }

    public function testMultiValueIsCappedAtCardinality(): void
    {
        $normalizer = $this->normalizer([
            $this->definition('page', 'tags', 'text', cardinality: 3),
        ]);

        $out = $normalizer->normalize('page', [
            'tags' => ['a', 'b', 'c', 'd', 'e'],
        ], null, 'en');

        self::assertSame(['tags' => ['a', 'b', 'c']], $out);
    }

    public function testUnlimitedCardinalityKeepsAll(): void
    {
        $normalizer = $this->normalizer([
            $this->definition('page', 'tags', 'text', cardinality: FieldDefinition::UNLIMITED),
        ]);

        $out = $normalizer->normalize('page', ['tags' => ['a', '', 'b', '   ', 'c']], null, 'en');

        self::assertSame(['tags' => ['a', 'b', 'c']], $out);
    }

    public function testEmptyValuesAreOmittedNotStoredAsNull(): void
    {
        $normalizer = $this->normalizer([
            $this->definition('page', 'subtitle', 'text'),
            $this->definition('page', 'link', 'url'),
        ]);

        $out = $normalizer->normalize('page', ['subtitle' => '', 'link' => 'not-a-url'], null, 'en');

        self::assertSame([], $out);
    }

    public function testUnknownFieldTypeIsSkipped(): void
    {
        $normalizer = $this->normalizer([
            $this->definition('page', 'weird', 'no_such_type'),
            $this->definition('page', 'ok', 'text'),
        ]);

        $out = $normalizer->normalize('page', ['weird' => 'x', 'ok' => 'y'], null, 'en');

        self::assertSame(['ok' => 'y'], $out);
    }

    /**
     * @param list<FieldDefinition> $definitions
     */
    private function normalizer(array $definitions): FieldValueNormalizer
    {
        return new FieldValueNormalizer(
            $this->fieldDefinitionRegistry($definitions),
            $this->fieldTypeRegistry(),
        );
    }
}
