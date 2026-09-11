<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Type\BooleanFieldType;
use App\Core\Field\Type\DateFieldType;
use App\Core\Field\Type\DecimalFieldType;
use App\Core\Field\Type\EmailFieldType;
use App\Core\Field\Type\IntegerFieldType;
use App\Core\Field\Type\RichTextFieldType;
use App\Core\Field\Type\SelectFieldType;
use App\Core\Field\Type\TextFieldType;
use App\Core\Field\Type\UrlFieldType;
use App\Core\TextFormat\TextFormatRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFieldType::class)]
#[CoversClass(RichTextFieldType::class)]
#[CoversClass(SelectFieldType::class)]
#[CoversClass(UrlFieldType::class)]
#[CoversClass(EmailFieldType::class)]
#[CoversClass(IntegerFieldType::class)]
#[CoversClass(DecimalFieldType::class)]
#[CoversClass(DateFieldType::class)]
#[CoversClass(BooleanFieldType::class)]
final class FieldTypeTest extends TestCase
{
    use FieldTestTrait;

    public function testTextStripsTagsAndCapsLength(): void
    {
        $type = new TextFieldType();
        $loose = $this->context($this->definition('page', 'x', 'text', settings: ['max_length' => 255]));
        $tight = $this->context($this->definition('page', 'x', 'text', settings: ['max_length' => 10]));

        self::assertSame('hello world', $type->normalize("<b>hello</b>\n  world", $loose));
        self::assertSame(10, mb_strlen((string) $type->normalize(str_repeat('a', 50), $tight)));
        self::assertNull($type->normalize('   ', $loose));
    }

    public function testRichTextRunsThroughSanitizer(): void
    {
        $type = new RichTextFieldType($this->textFormatProcessor(), $this->textFormatAccess(), $this->textFormatRegistry());
        $ctx = $this->context($this->definition('page', 'body', 'rich_text'));

        $out = $type->normalize('<p onclick="evil()">safe <script>alert(1)</script></p>', $ctx);

        self::assertIsArray($out);
        self::assertSame(TextFormatRegistry::BASIC_HTML, $out['format']);
        self::assertStringNotContainsString('<script', $out['value']);
        self::assertStringNotContainsString('onclick', $out['value']);
        self::assertStringContainsString('safe', $out['value']);
    }

    public function testUrlRejectsNonHttpSchemes(): void
    {
        $type = new UrlFieldType();
        $ctx = $this->context($this->definition('page', 'link', 'url'));

        self::assertSame('https://example.com/x', $type->normalize('https://example.com/x', $ctx));
        self::assertNull($type->normalize('javascript:alert(1)', $ctx));
        self::assertNull($type->normalize('file:///etc/passwd', $ctx));
        self::assertNull($type->normalize('not a url', $ctx));
    }

    public function testEmailValidation(): void
    {
        $type = new EmailFieldType();
        $ctx = $this->context($this->definition('page', 'mail', 'email'));

        self::assertSame([], $type->validate('a@b.com', $ctx));
        self::assertSame(['field.violation.invalid_email'], $type->validate('nope', $ctx));
    }

    public function testIntegerRangeValidation(): void
    {
        $type = new IntegerFieldType();
        $ctx = $this->context($this->definition('page', 'n', 'integer', settings: ['min' => 1, 'max' => 10]));

        self::assertSame(5, $type->normalize('5', $ctx));
        self::assertNull($type->normalize('abc', $ctx));
        self::assertSame([], $type->validate(5, $ctx));
        self::assertSame(['field.violation.too_small'], $type->validate(0, $ctx));
        self::assertSame(['field.violation.too_large'], $type->validate(99, $ctx));
    }

    public function testDecimalKeepsScaleAsString(): void
    {
        $type = new DecimalFieldType();
        $ctx = $this->context($this->definition('page', 'price', 'decimal', settings: ['scale' => 2]));

        self::assertSame('19.90', $type->normalize('19.9', $ctx));
        self::assertSame('19.90', $type->normalize(19.899, $ctx));
    }

    public function testDateNormalizesToIso(): void
    {
        $type = new DateFieldType();
        $ctx = $this->context($this->definition('page', 'd', 'date'));

        self::assertSame('2026-03-14', $type->normalize('2026-03-14', $ctx));
        self::assertSame('2026-03-14', $type->normalize(new \DateTimeImmutable('2026-03-14 09:00'), $ctx));
        self::assertNull($type->normalize('14/03/2026', $ctx));
    }

    public function testBooleanAlwaysResolves(): void
    {
        $type = new BooleanFieldType();
        $ctx = $this->context($this->definition('page', 'flag', 'boolean'));

        self::assertTrue($type->normalize('1', $ctx));
        self::assertTrue($type->normalize('on', $ctx));
        self::assertFalse($type->normalize('0', $ctx));
        self::assertFalse($type->normalize(null, $ctx));
        self::assertSame(1, $type->indexValue(true));
        self::assertSame(0, $type->indexValue(false));
    }

    public function testSelectEnforcesChoices(): void
    {
        $type = new SelectFieldType();
        $ctx = $this->context($this->definition('page', 'size', 'select', settings: ['choices' => "s|Small\nm|Medium\nl|Large"]));

        self::assertSame('m', $type->normalize('m', $ctx));
        self::assertNull($type->normalize('xl', $ctx));
        self::assertSame(['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'], $type->choicesFor($ctx));
    }
}
