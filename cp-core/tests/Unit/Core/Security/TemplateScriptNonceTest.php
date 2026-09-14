<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Static guard: every executed <script> tag in a shipped template must carry
 * the CSP nonce.
 *
 * In strict mode script-src is "'self' 'nonce-X' https:". A nonce in the list
 * makes the browser IGNORE 'unsafe-inline', so an INLINE script without the
 * attribute is not degraded, it is dead — and only in the mode the operator
 * turned on for safety. That failure is invisible in the balanced default,
 * which is why this is checked statically instead of trusted to a
 * rendered-page test.
 *
 * Data blocks (<script type="application/json"> and ld+json) are exempt: they
 * are never prepared for execution, so CSP never checks them.
 */
#[CoversNothing]
final class TemplateScriptNonceTest extends TestCase
{
    private const NONCE_CALL = 'csp_nonce_attr()';

    /** @var list<string> */
    private const EXEMPT_TYPES = ['application/json', 'application/ld+json'];

    /** @var list<string> project-relative template roots that ship to users */
    private const ROOTS = [
        'cp-core/templates',
        'cp-content/modules',
        'cp-content/themes',
    ];

    public function testEveryExecutedScriptTagCarriesTheCspNonce(): void
    {
        $offenders = [];
        $scanned = 0;
        $checked = 0;

        foreach ($this->templates() as $path => $relative) {
            ++$scanned;
            $source = (string) file_get_contents($path);

            if (!preg_match_all('/<script\b[^>]*>/i', $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$tag, $offset]) {
                if ($this->isDataBlock($tag)) {
                    continue;
                }

                ++$checked;

                if (!str_contains($tag, self::NONCE_CALL)) {
                    $line = substr_count($source, "\n", 0, $offset) + 1;
                    $offenders[] = sprintf('%s:%d  %s', $relative, $line, trim($tag));
                }
            }
        }

        self::assertGreaterThan(0, $scanned, 'No templates were scanned; the roots are wrong.');
        self::assertGreaterThan(0, $checked, 'No script tags were checked; the matcher is wrong.');
        self::assertSame([], $offenders, sprintf(
            "These script tags would be blocked under strict CSP. Add {{ %s }} right after <script:\n%s",
            self::NONCE_CALL,
            implode("\n", $offenders),
        ));
    }

    /**
     * @return iterable<string, string> absolute path => project-relative path
     */
    private function templates(): iterable
    {
        // cp-core/tests/Unit/Core/Security -> ... -> project root
        $projectDir = $this->toPosix(\dirname(__DIR__, 5));

        foreach (self::ROOTS as $root) {
            $absoluteRoot = $projectDir.'/'.$root;
            if (!is_dir($absoluteRoot)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }

                $path = $this->toPosix($file->getPathname());
                $relative = ltrim(substr($path, \strlen($absoluteRoot)), '/');

                yield $path => $root.'/'.$relative;
            }
        }
    }

    private function toPosix(string $path): string
    {
        return strtr($path, [\DIRECTORY_SEPARATOR => '/']);
    }

    private function isDataBlock(string $tag): bool
    {
        foreach (self::EXEMPT_TYPES as $type) {
            if (str_contains($tag, 'type="'.$type.'"') || str_contains($tag, "type='".$type."'")) {
                return true;
            }
        }

        return false;
    }
}
