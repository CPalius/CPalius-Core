<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Theme;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeFileEditor;
use App\Core\Theme\ThemeSourceLinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ThemeFileEditor::class)]
final class ThemeFileEditorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-theme-edit-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/cp-content/themes/demo/Resources/views', 0775, true);
        mkdir($this->projectDir.'/cp-content/themes/demo/Resources/dist', 0775, true);
        file_put_contents($this->projectDir.'/cp-content/themes/demo/Resources/views/layout.html.twig', '{{ title }}');
        file_put_contents($this->projectDir.'/cp-content/themes/demo/Resources/dist/app.js', 'console.log(1);');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testListsTwigAndJsAndRejectsTraversal(): void
    {
        $editor = $this->editor();
        $theme = $this->theme();
        $paths = array_column($editor->listFiles($theme), 'path');

        self::assertContains('views/layout.html.twig', $paths);
        self::assertContains('dist/app.js', $paths);

        $this->expectException(\InvalidArgumentException::class);
        $editor->absolutePath($theme, '../layout.html.twig');
    }

    public function testWriteRefusesBrokenTwigUnlessForced(): void
    {
        $editor = $this->editor();
        $theme = $this->theme();
        $broken = '{% if true %}';

        $problems = $editor->write($theme, 'views/layout.html.twig', $broken, false);
        self::assertNotSame([], $problems);
        self::assertSame('{{ title }}', file_get_contents($this->projectDir.'/cp-content/themes/demo/Resources/views/layout.html.twig'));

        $forced = $editor->write($theme, 'views/layout.html.twig', $broken, true);
        self::assertNotSame([], $forced);
        self::assertSame($broken, file_get_contents($this->projectDir.'/cp-content/themes/demo/Resources/views/layout.html.twig'));
    }

    private function editor(): ThemeFileEditor
    {
        return new ThemeFileEditor(
            new ThemeSourceLinter(),
            $this->projectDir,
            $this->projectDir.'/cp-content/themes',
        );
    }

    private function theme(): ThemeDefinition
    {
        return new ThemeDefinition('demo', 'Demo');
    }
}
