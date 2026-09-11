<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Theme;

use App\Core\Theme\ThemeSourceLinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeSourceLinter::class)]
final class ThemeSourceLinterTest extends TestCase
{
    private ThemeSourceLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new ThemeSourceLinter();
    }

    public function testValidTwigHasNoProblems(): void
    {
        self::assertSame([], $this->linter->problems('twig', '{{ title }}', 'ok.html.twig'));
    }

    public function testBrokenTwigReportsLine(): void
    {
        $problems = $this->linter->problems('twig', '{% if true %}', 'broken.html.twig');

        self::assertNotSame([], $problems);
        self::assertStringContainsString('Twig', $problems[0]);
    }

    public function testUnclosedJsBraceIsReported(): void
    {
        $problems = $this->linter->problems('js', 'function x() { return 1;', 'app.js');

        self::assertNotSame([], $problems);
        self::assertStringContainsString('unclosed', $problems[0]);
    }

    public function testValidCssPasses(): void
    {
        self::assertSame([], $this->linter->problems('css', '.hero { color: #fff; }', 'style.css'));
    }
}
