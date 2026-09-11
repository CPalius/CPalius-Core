<?php

declare(strict_types=1);

namespace App\Core\Module;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * WordPress-style package lifecycle: ZIP in, folder out, optional overwrite.
 * Never writes into cp-core; modules live only under cp-content/modules.
 */
final class ModulePackageService
{
    private const MAX_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly string $modulesDir,
        private readonly ModuleActivator $moduleActivator,
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
            return ['success' => false, 'message' => 'Module ZIP must be 32 MB or smaller.', 'dirName' => null];
        }

        $original = strtolower((string) $file->getClientOriginalName());
        if (!str_ends_with($original, '.zip')) {
            return ['success' => false, 'message' => 'Only .zip module packages are accepted.', 'dirName' => null];
        }

        $tmpZip = $file->getPathname();
        $extractDir = sys_get_temp_dir().'/cpalius-module-'.bin2hex(random_bytes(8));

        try {
            $unpacked = $this->extractZip($tmpZip, $extractDir);
            if (!$unpacked['success']) {
                return ['success' => false, 'message' => $unpacked['message'], 'dirName' => null, 'problems' => $unpacked['problems'] ?? []];
            }

            $sourceDir = $unpacked['moduleDir'];
            $dirName = $unpacked['dirName'];
            $targetDir = rtrim($this->modulesDir, '/\\').'/'.$dirName;

            $problems = ModulePackageContract::problems($sourceDir);
            if ($problems !== []) {
                return [
                    'success' => false,
                    'message' => 'The package does not satisfy the CPalius module contract.',
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

            $filesystem->mkdir($this->modulesDir);
            $filesystem->mirror($sourceDir, $targetDir);

            return [
                'success' => true,
                'message' => sprintf('"%s" was installed under cp-content/modules.', $dirName),
                'dirName' => $dirName,
            ];
        } finally {
            (new Filesystem())->remove($extractDir);
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function delete(string $dirName, bool $purgeData = false): array
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $dirName) !== 1) {
            return ['success' => false, 'message' => 'Invalid module directory name.'];
        }

        $targetDir = rtrim($this->modulesDir, '/\\').'/'.$dirName;
        if (!is_dir($targetDir)) {
            return ['success' => false, 'message' => sprintf('"%s" is not installed.', $dirName)];
        }

        $deactivate = $this->moduleActivator->deactivate($dirName, $purgeData);
        if (!$deactivate['success']) {
            return ['success' => false, 'message' => $deactivate['message']];
        }

        (new Filesystem())->remove($targetDir);

        return [
            'success' => true,
            'message' => $purgeData
                ? sprintf('"%s" was deleted and its data was removed.', $dirName)
                : sprintf('"%s" was deleted from the filesystem. Data was kept.', $dirName),
        ];
    }

    /**
     * @param array{description?: string, author?: string} $fields
     *
     * @return array{success: bool, message: string}
     */
    public function updateManifest(string $dirName, array $fields): array
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $dirName) !== 1) {
            return ['success' => false, 'message' => 'Invalid module directory name.'];
        }

        $moduleDir = rtrim($this->modulesDir, '/\\').'/'.$dirName;
        $manifestFile = $moduleDir.'/module.json';
        if (!is_file($manifestFile)) {
            return ['success' => false, 'message' => 'module.json was not found.'];
        }

        try {
            $data = json_decode((string) file_get_contents($manifestFile), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'module.json could not be parsed.'];
        }

        if (!\is_array($data)) {
            return ['success' => false, 'message' => 'module.json is invalid.'];
        }

        if (array_key_exists('description', $fields) && \is_string($fields['description'])) {
            $data['description'] = $fields['description'];
        }
        if (array_key_exists('author', $fields) && \is_string($fields['author'])) {
            $data['author'] = $fields['author'];
        }

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['success' => false, 'message' => 'module.json could not be encoded.'];
        }

        file_put_contents($manifestFile, $json."\n");

        return ['success' => true, 'message' => sprintf('"%s" metadata was updated.', $dirName)];
    }

    /**
     * @return array{success: bool, message: string, moduleDir?: string, dirName?: string, problems?: list<string>}
     */
    private function extractZip(string $zipPath, string $extractDir): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['success' => false, 'message' => 'PHP zip extension is required to install modules from a ZIP file.'];
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

        $moduleDir = $this->locateModuleRoot($extractDir);
        if ($moduleDir === null) {
            return ['success' => false, 'message' => 'The ZIP archive does not contain a module.json file.'];
        }

        $dirName = basename($moduleDir);
        if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $dirName) !== 1) {
            $manifest = ModuleManifest::fromDirectory($moduleDir);
            $dirName = $manifest?->name ?? $dirName;
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $dirName) !== 1) {
            return [
                'success' => false,
                'message' => 'Module directory name must be PascalCase alphanumeric (e.g. Pages).',
            ];
        }

        return ['success' => true, 'message' => 'ok', 'moduleDir' => $moduleDir, 'dirName' => $dirName];
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

            $entryProblems = ModulePackageContract::zipEntryProblems($name);
            foreach ($entryProblems as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private function locateModuleRoot(string $extractDir): ?string
    {
        $direct = $extractDir.'/module.json';
        if (is_file($direct)) {
            return $extractDir;
        }

        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $extractDir.'/'.$entry;
            if (is_dir($candidate) && is_file($candidate.'/module.json')) {
                return $candidate;
            }
        }

        return null;
    }
}
