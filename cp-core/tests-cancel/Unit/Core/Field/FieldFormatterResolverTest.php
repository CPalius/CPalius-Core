<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Display\FieldFormatterResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldFormatterResolver::class)]
final class FieldFormatterResolverTest extends TestCase
{
    use FieldTestTrait;

    public function testTextIsEscaped(): void
    {
        $out = $this->formatterResolver()->format($this->definition('page', 'x', 'text'), '<b>hi</b> & "q"', 'en');

        self::assertSame('&lt;b&gt;hi&lt;/b&gt; &amp; &quot;q&quot;', $out);
    }

    public function testRichTextRunsTheFormatPipeline(): void
    {
        $out = $this->formatterResolver()->format($this->definition('page', 'body', 'rich_text'), '<p>already safe</p>', 'en');

        self::assertStringContainsString('already safe', $out);
        self::assertStringNotContainsString('<script', $out);
    }

    public function testUrlBecomesLink(): void
    {
        $out = $this->formatterResolver()->format($this->definition('page', 'l', 'url'), 'https://example.com/a?b=1', 'en');

        self::assertStringContainsString('href="https://example.com/a?b=1"', $out);
        self::assertStringContainsString('rel="nofollow noopener"', $out);
    }

    public function testSelectShowsLabelNotValue(): void
    {
        $def = $this->definition('page', 'size', 'select', settings: ['choices' => "s|Small\nl|Large"]);

        self::assertSame('Large', $this->formatterResolver()->format($def, 'l', 'en'));
    }

    public function testBooleanUsesTranslatedLabel(): void
    {
        $def = $this->definition('page', 'flag', 'boolean');
        $resolver = $this->formatterResolver();

        self::assertSame('field.value.yes', $resolver->format($def, true, 'en'));
        self::assertSame('field.value.no', $resolver->format($def, false, 'en'));
    }

    public function testEmptyValueIsEmptyString(): void
    {
        self::assertSame('', $this->formatterResolver()->format($this->definition('page', 'x', 'text'), null, 'en'));
        self::assertSame('', $this->formatterResolver()->format($this->definition('page', 'x', 'text'), '', 'en'));
    }
}
