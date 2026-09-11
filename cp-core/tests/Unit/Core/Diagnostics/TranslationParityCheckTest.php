<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Diagnostics;

use App\Core\Diagnostics\Check\TranslationParityCheck;
use App\Core\Diagnostics\DoctorFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Uses a real temporary project tree rather than mocks: the check's whole job
 * is reading files off disk, and a mocked filesystem would test nothing.
 */
#[CoversClass(TranslationParityCheck::class)]
final class TranslationParityCheckTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cp-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/cp-content/translations', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->projectDir);
    }

    public function testEqualCataloguesPass(): void
    {
        $this->write('messages.en.yaml', "a: One\nb: Two\n");
        $this->write('messages.tr.yaml', "a: Bir\nb: İki\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->isPass());
    }

    public function testAMissingKeyIsReportedAgainstTheLocaleThatLacksIt(): void
    {
        $this->write('messages.en.yaml', "a: One\nb: Two\n");
        $this->write('messages.tr.yaml', "a: Bir\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertCount(1, $findings);
        self::assertSame('translations.missing_keys', $findings[0]->id);
        self::assertStringContainsString('(tr)', $findings[0]->title);
        self::assertStringContainsString('b', $findings[0]->detail);
    }

    public function testNoLocaleIsPrivileged(): void
    {
        // A key that exists only in Turkish is just as much a gap as one that
        // exists only in English — English is the fallback, not the reference.
        $this->write('messages.en.yaml', "a: One\n");
        $this->write('messages.tr.yaml', "a: Bir\nextra: Fazladan\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertCount(1, $findings);
        self::assertStringContainsString('(en)', $findings[0]->title);
        self::assertStringContainsString('extra', $findings[0]->detail);
    }

    public function testNestedKeysAreComparedByFullPath(): void
    {
        // Equal line counts, different keys: exactly the case a naive
        // "wc -l" comparison would call healthy.
        $this->write('messages.en.yaml', "aacp:\n    menu:\n        logs: Logs\n");
        $this->write('messages.tr.yaml', "aacp:\n    menu:\n        queue: Kuyruk\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        $details = implode(' ', array_map(static fn (DoctorFinding $f): string => $f->detail, $findings));
        self::assertStringContainsString('aacp.menu.logs', $details);
        self::assertStringContainsString('aacp.menu.queue', $details);
    }

    public function testASingleLocaleDomainIsNotReported(): void
    {
        $this->write('only.en.yaml', "a: One\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->isPass());
    }

    public function testModuleCataloguesAreScannedToo(): void
    {
        mkdir($this->projectDir.'/cp-content/modules/Blog/Resources/translations', 0777, true);
        file_put_contents($this->projectDir.'/cp-content/modules/Blog/Resources/translations/messages.en.yaml', "blog: Blog\npost: Post\n");
        file_put_contents($this->projectDir.'/cp-content/modules/Blog/Resources/translations/messages.tr.yaml', "blog: Blog\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertSame('translations.missing_keys', $findings[0]->id);
        self::assertStringContainsString('post', $findings[0]->detail);
    }

    public function testAMalformedCatalogueIsSkippedRatherThanFatal(): void
    {
        // Reporting broken YAML is lint:yaml's job; the doctor must not die on it.
        $this->write('messages.en.yaml', "a: One\n");
        $this->write('messages.tr.yaml', "a: [unclosed\n");

        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertNotSame([], $findings);
    }

    public function testAnInstallationWithNoCataloguesIsReportedNotCrashed(): void
    {
        $findings = (new TranslationParityCheck($this->projectDir))->run();

        self::assertCount(1, $findings);
        self::assertSame('translations.none', $findings[0]->id);
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->projectDir.'/cp-content/translations/'.$name, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
