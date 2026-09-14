<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Content\RichTextSanitizer;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Composer images are stored as /uploads/... — a relative media URL.
 * allow_relative_links does not cover img src; without allow_relative_medias
 * the sanitizer keeps the tag and drops the only attribute that matters.
 */
#[CoversClass(RichTextSanitizer::class)]
final class RichTextSanitizerMediaTest extends IntegrationTestCase
{
    public function testALocalUploadSrcSurvivesSanitize(): void
    {
        $html = '<figure class="image"><img src="/uploads/2026/09/abcdef.gif" width="120" height="80" alt="dot"></figure>';

        $out = $this->sanitizer()->sanitize($html);

        self::assertStringContainsString('src="/uploads/2026/09/abcdef.gif"', $out);
        self::assertStringContainsString('width="120"', $out);
    }

    public function testAJavascriptImageSrcIsDropped(): void
    {
        $out = $this->sanitizer()->sanitize('<img src="javascript:alert(1)" alt="x">');

        self::assertStringNotContainsString('javascript:', $out);
    }

    private function sanitizer(): RichTextSanitizer
    {
        /** @var RichTextSanitizer $sanitizer */
        $sanitizer = $this->container()->get(RichTextSanitizer::class);

        return $sanitizer;
    }
}
