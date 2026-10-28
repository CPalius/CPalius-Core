<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Malware\MalwareGuard;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * WordPress-style theme ZIP install. Never writes into cp-core.
 */
final class ThemePackageService
{
    private const MAX_BYTES = 32 * 1024 * 1024;
    private const MAX_UNPACKED_BYTES = 128 * 1024 * 1024;
    private const MAX_ENTRIES = 3000;
    private const MAX_LINT_BYTES = 1048576;

    public function __construct(
        private readonly string $themesDir,
        private readonly ?ThemeSourceLinter $linter = null,
        private readonly ?MalwareGuard $malwareGuard = null,
    ) {
    }

    /**
     * @return array{success: bool, message: string, dirName: ?string, problems?: list<string>}
     */
    public function installFromUpload(UploadedFile $file, bool $overwrite = false): array
    {
        if (!$file->isValid()) {
            return ['success' => false, 'message' => 'The uploaded file is not valid.', 'dirName' => null];
        }

        if ($file->getSize() !== false && $file->getSize() > self::MAX_BYTES) {
            return ['success' => false, 'message' => 'Theme ZIP must be 32 MB or smaller.', 'dirName' => null];
        }

        $original = strtolower((string) $file->getClientOriginalName());
        if (!str_ends_with($original, '.zip')) {
            return ['success' => false, 'message' => 'Only .zip theme packages are accepted.', 'dirName' => null];
        }

        $extractDir = sys_get_temp_dir().'/cpalius-theme-'.bin2hex(random_bytes(8));

        try {
            $unpacked = $this->extractZip($file->getPathname(), $extractDir);
            if (!$unpacked['success']) {
                return ['success' => false, 'message' => $unpacked['message'], 'dirName' => null, 'problems' => $unpacked['problems'] ?? []];
            }

            $sourceDir = $unpacked['themeDir'];
            $dirName = $unpacked['dirName'];
            $targetDir = rtrim($this->themesDir, '/\\').'/'.$dirName;

            $problems = ThemePackageContract::problems($sourceDir);
            if ($problems === []) {
                $problems = $this->lintSources($sourceDir);
            }
            if ($problems === [] && $this->malwareGuard !== null) {
                $problems = MalwareGuard::describe($this->malwareGuard->blockingFindingsForDirectory($sourceDir, 'theme_upload'));
            }
            if ($problems !== []) {
                return [
                    'success' => false,
                    'message' => 'The package does not satisfy the CPalius theme contract.',
                    'dirName' => $dirName,
                    'problems' => $problems,
                ];
            }

            $filesystem = new Filesystem();
            if (is_dir($targetDir)) {
                if (!$overwrite) {
                    return [
                        'success' => false,
                        'message' => sprintf('"%s" is already installed. Enable overwrite to replace it.', $dirName),
                        'dirName' => $dirName,
                    ];
                }
                $filesystem->remove($targetDir);
            }

            $filesystem->mkdir($this->themesDir);
            $filesystem->mirror($sourceDir, $targetDir);

            return [
                'success' => true,
                'message' => sprintf('"%s" was installed under cp-content/themes.', $dirName),
                'dirName' => $dirName,
            ];
        } finally {
            (new Filesystem())->remove($extractDir);
        }
    }

    /**
     * Parse-checks .twig and .css files ("file: message"). Files over 1 MB are skipped.
     * .js is not linted: the bracket-balance linter misreads regex literals in real bundles (false rejects).
     *
     * @return list<string>
     */
    private function lintSources(string $themeDir): array
    {
        if ($this->linter === null) {
            return [];
        }

        $problems = [];
        $root = rtrim($themeDir, '/\\');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $name = $file->getFilename();
            $kind = str_ends_with($name, '.twig') ? 'twig' : (str_ends_with($name, '.css') ? 'css' : null);
            if ($kind === null || !$file->isFile() || $file->isLink() || $file->getSize() > self::MAX_LINT_BYTES) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($root))), '/');
            foreach ($this->linter->problems($kind, (string) file_get_contents($file->getPathname()), $relative) as $problem) {
                $problems[] = $relative.': '.$problem;
            }
        }

        return $problems;
    }

    /**
     * @return array{success: bool, message: string, themeDir?: string, dirName?: string, problems?: list<string>}
     */
    public function extractZip(string $zipPath, string $extractDir): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['success' => false, 'message' => 'PHP zip extension is required to install themes from a ZIP file.'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'The ZIP archive could not be opened.'];
        }

        try {
            $unsafe = $this->scanZipEntries($zip);
            if ($unsafe !== []) {
                return ['success' => false, 'message' => 'The ZIP archive contains unsafe paths.', 'problems' => $unsafe];
            }

            if (!@mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
                return ['success' => false, 'message' => 'Temporary extract directory could not be created.'];
            }

            if (!$zip->extractTo($extractDir)) {
                return ['success' => false, 'message' => 'The ZIP archive could not be extracted.'];
            }
        } finally {
            $zip->close();
        }

        $themeDir = $this->locateThemeRoot($extractDir);
        if ($themeDir === null) {
            return ['success' => false, 'message' => 'The ZIP archive does not contain a theme.json file.'];
        }

        $dirName = basename($themeDir);
        if (preg_match(ThemePackageContract::DIR_NAME_PATTERN, $dirName) !== 1) {
            return [
                'success' => false,
                'message' => 'Theme directory name must be kebab-case (e.g. my-theme).',
            ];
        }

        return ['success' => true, 'message' => 'ok', 'themeDir' => $themeDir, 'dirName' => $dirName];
    }

    /**
     * @return list<string>
     */
    private function scanZipEntries(\ZipArchive $zip): array
    {
        $problems = [];
        $count = $zip->numFiles;

        // Zip-bomb guard: the 32 MB limit is the compressed size, so bound entries and unpacked size too.
        if ($count > self::MAX_ENTRIES) {
            $problems[] = sprintf('The archive has %d entries; the limit is %d.', $count, self::MAX_ENTRIES);

            return $problems;
        }
        $unpacked = 0;

        for ($i = 0; $i < $count; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!\is_string($name) || $name === '') {
                continue;
            }

            $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                $problems[] = 'The archive unpacks to more than 128 MB.';

                return $problems;
            }

            // Unix symlink entries (mode 0120000) could point outside the theme folder once extracted.
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                $problems[] = sprintf('Symbolic links are not allowed: %s', $name);
            }

            foreach (ThemePackageContract::zipEntryProblems($name) as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private function locateThemeRoot(string $extractDir): ?string
    {
        if (is_file($extractDir.'/theme.json')) {
            return $extractDir;
        }

        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $extractDir.'/'.$entry;
            if (is_dir($candidate) && is_file($candidate.'/theme.json')) {
                return $candidate;
            }
        }

        return null;
    }
}
