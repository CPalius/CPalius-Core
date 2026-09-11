<?php

declare(strict_types=1);

namespace App\Core\Theme;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * WordPress-style theme ZIP install. Never writes into cp-core.
 */
final class ThemePackageService
{
    private const MAX_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly string $themesDir,
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
     * @return array{success: bool, message: string, themeDir?: string, dirName?: string, problems?: list<string>}
     */
    private function extractZip(string $zipPath, string $extractDir): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['success' => false, 'message' => 'PHP zip extension is required to install themes from a ZIP file.'];
        }

        $zip = new ZipArchive();
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
    private function scanZipEntries(ZipArchive $zip): array
    {
        $problems = [];
        $count = $zip->numFiles;

        for ($i = 0; $i < $count; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!\is_string($name) || $name === '') {
                continue;
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
