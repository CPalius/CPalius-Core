<?php

declare(strict_types=1);

namespace App\Core\Extension;

/**
 * Publisher-side tooling (run from CPalius-Gereklilikler/yayinla-eklenti.sh): builds a module or
 * theme ZIP, records it in the extension index and signs the index. Plain PHP, no framework.
 */
final class ExtensionPublisher
{
    public const VALIDITY_DAYS = 45;

    private const EXCLUDED_DIRS = ['Tests', '.git', 'node_modules', '.idea'];
    private const EXCLUDED_FILES = ['.DS_Store', 'Thumbs.db'];

    /**
     * @param string $type "modules" or "themes"
     *
     * @return array{name: string, version: string, file: string, sha256: string, size: int, manifest: array<string, mixed>, screenshot: ?array{path: string, sha256: string}}
     */
    public function buildPackage(string $type, string $sourceDir, string $outputRoot, bool $withZip = true): array
    {
        $manifestFile = $sourceDir.'/'.($type === 'modules' ? 'module.json' : 'theme.json');
        $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;

        if (!\in_array($type, ['modules', 'themes'], true) || !\is_array($manifest)) {
            throw new \InvalidArgumentException('Not a module or theme directory: '.$sourceDir);
        }

        $dirName = basename($sourceDir);
        $version = (string) ($manifest['version'] ?? '');

        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $dirName) !== 1 || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new \InvalidArgumentException($dirName.': directory name or version (x.y.z) is not valid.');
        }

        // Paid listings are shown in the store only: no archive is built, so there is nothing to download.
        if (!$withZip) {
            return [
                'name' => $dirName,
                'version' => $version,
                'file' => '',
                'sha256' => '',
                'size' => 0,
                'manifest' => $manifest,
                'screenshot' => is_dir($outputRoot.'/'.$type.'/'.$dirName) || mkdir($outputRoot.'/'.$type.'/'.$dirName, 0775, true) ? $this->publishScreenshot($type, $dirName, $sourceDir, $outputRoot) : null,
            ];
        }

        $relative = $type.'/'.$dirName.'/'.$dirName.'-'.$version.'.zip';
        $target = $outputRoot.'/'.$relative;

        if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0775, true) && !is_dir(\dirname($target))) {
            throw new \RuntimeException('Cannot create '.\dirname($target));
        }

        @unlink($target);
        $zip = new \ZipArchive();

        if ($zip->open($target, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Cannot create '.$target);
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($f->isDir() && \in_array($f->getFilename(), self::EXCLUDED_DIRS, true)) && !\in_array($f->getFilename(), self::EXCLUDED_FILES, true),
        ));

        foreach ($iterator as $file) {
            // A theme carries no server-side code (the installer refuses it): dev tools like tools/recolor.php stay out.
            if ($file->isFile() && !($type === 'themes' && preg_match('/\.(php[0-9]?|phtml|phar|phps|pht)$/i', $file->getFilename()) === 1)) {
                $files[] = $file->getPathname();
            }
        }

        // Sorted so the same source always yields the same archive bytes.
        sort($files);

        foreach ($files as $path) {
            $inner = $dirName.'/'.ltrim(str_replace('\\', '/', substr($path, \strlen($sourceDir))), '/');
            $zip->addFile($path, $inner);
            $zip->setMtimeName($inner, 1700000000);
        }

        $zip->close();

        return [
            'name' => $dirName,
            'version' => $version,
            'file' => $relative,
            'sha256' => (string) hash_file('sha256', $target),
            'size' => (int) filesize($target),
            'manifest' => $manifest,
            'screenshot' => $this->publishScreenshot($type, $dirName, $sourceDir, $outputRoot),
        ];
    }

    /**
     * Copies screenshot.(png|jpg|webp) from the source folder to the server layout under a hashed name,
     * so a changed image is fetched again while an unchanged one is cached forever.
     *
     * @return array{path: string, sha256: string}|null
     */
    private function publishScreenshot(string $type, string $dirName, string $sourceDir, string $outputRoot): ?array
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $file = $sourceDir.'/screenshot.'.$extension;

            if (!is_file($file) || filesize($file) > 2097152) {
                continue;
            }

            $sha = (string) hash_file('sha256', $file);
            $relative = $type.'/'.$dirName.'/screenshot-'.substr($sha, 0, 12).'.'.$extension;

            if (!is_file($outputRoot.'/'.$relative)) {
                copy($file, $outputRoot.'/'.$relative);
            }

            return ['path' => $relative, 'sha256' => $sha];
        }

        return null;
    }

    /**
     * Records a built package in the index file, creating it when missing.
     *
     * @param array{name: string, version: string, file: string, sha256: string, size: int, manifest: array<string, mixed>, screenshot: ?array{path: string, sha256: string}} $package
     * @param array<string, mixed>                                                                                                                                                 $meta    store text from the catalogue file: title, summary, category, free, bundled
     */
    public function addToIndex(string $indexPath, string $type, array $package, array $meta, string $notes = ''): void
    {
        $index = is_file($indexPath) ? json_decode((string) file_get_contents($indexPath), true) : null;
        $index = \is_array($index) ? $index : ['schema' => 1, 'serial' => 0, 'modules' => [], 'themes' => []];
        $name = $package['name'];
        $manifest = $package['manifest'];

        $entry = $index[$type][$name] ?? ['versions' => []];
        $entry['title'] = (string) ($meta['title'] ?? $manifest['name'] ?? $name);
        $entry['summary'] = (string) ($meta['summary'] ?? $manifest['description'] ?? '');
        $entry['category'] = (string) ($meta['category'] ?? 'tools');
        $entry['free'] = (bool) ($meta['free'] ?? true);
        $entry['bundled'] = (bool) ($meta['bundled'] ?? false);
        $entry['extends'] = (string) ($manifest['extends'] ?? '');
        $entry['stability'] = (string) ($meta['stability'] ?? 'stable');
        $entry['features'] = array_values(array_map('strval', (array) ($meta['features'] ?? [])));
        $entry['screenshot'] = $package['screenshot'] ?? ($entry['screenshot'] ?? null);
        // Advisories belong to the catalogue file, not to a release: they are added after the fact, and an entry
        // without the key keeps whatever the index already says.
        if (\array_key_exists('advisories', $meta)) {
            $entry['advisories'] = self::cleanAdvisories($name, (array) $meta['advisories']);
        }
        $version = [
            'core_version' => (string) ($manifest['core_version'] ?? ''),
            'requires' => (object) (array) ($manifest['requires'] ?? []),
            'released_at' => date('Y-m-d'),
            'notes' => $notes,
        ];

        // A paid listing carries no download address at all.
        if ($package['file'] !== '') {
            $version = ['zip' => $package['file'], 'sha256' => $package['sha256'], 'size' => $package['size']] + $version;
        } elseif ($entry['free']) {
            throw new \InvalidArgumentException($name.': a free extension needs a package.');
        }

        $entry['versions'][$package['version']] = $version;

        $versions = array_keys($entry['versions']);
        usort($versions, 'version_compare');
        $entry['latest'] = (string) end($versions);

        $index[$type][$name] = $entry;
        $this->write($indexPath, $index);
    }

    /**
     * Refuses an advisory that would be read wrongly later: this list decides which installs are told to update.
     *
     * @param array<int|string, mixed> $advisories
     *
     * @return list<array<string, string>>
     */
    private static function cleanAdvisories(string $name, array $advisories): array
    {
        $out = [];

        foreach ($advisories as $a) {
            $a = (array) $a;
            $id = (string) ($a['id'] ?? '');
            $affected = (string) ($a['affected'] ?? '');
            $severity = (string) ($a['severity'] ?? '');
            $url = (string) ($a['url'] ?? '');

            if ($id === '' || preg_match('~^(\*|[<>=\s\d.,|a-z-]+)$~i', $affected) !== 1 || $affected === '') {
                throw new \InvalidArgumentException($name.': advisory "'.$id.'" needs an id and an "affected" range such as "<1.2.0".');
            }
            if (!\in_array($severity, ['critical', 'high', 'medium', 'low'], true)) {
                throw new \InvalidArgumentException($name.': advisory "'.$id.'" severity must be critical, high, medium or low.');
            }
            if ($url !== '' && preg_match('~^https://~i', $url) !== 1) {
                throw new \InvalidArgumentException($name.': advisory "'.$id.'" url must be https.');
            }

            $out[] = [
                'id' => $id,
                'affected' => $affected,
                'fixed' => (string) ($a['fixed'] ?? ''),
                'severity' => $severity,
                'summary' => mb_substr((string) ($a['summary'] ?? ''), 0, 300),
                'url' => $url,
            ];
        }

        return $out;
    }
    /**
     * Bumps the serial, sets the lifetime and writes the detached signature next to the index.
     */
    public function finalize(string $indexPath, string $signaturePath, string $secretKeyFile, int $validityDays = self::VALIDITY_DAYS): int
    {
        $index = json_decode((string) file_get_contents($indexPath), true);
        $key = is_file($secretKeyFile) ? base64_decode(trim((string) file_get_contents($secretKeyFile)), true) : false;

        if (!\is_array($index) || $key === false || \strlen($key) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Index or signing key is missing or invalid.');
        }

        $index['serial'] = (int) ($index['serial'] ?? 0) + 1;
        $index['generated_at'] = gmdate(\DATE_ATOM);
        $index['expires_at'] = gmdate(\DATE_ATOM, time() + $validityDays * 86400);
        $this->write($indexPath, $index);

        file_put_contents($signaturePath, base64_encode(sodium_crypto_sign_detached((string) file_get_contents($indexPath), $key))."\n");
        sodium_memzero($key);

        return (int) $index['serial'];
    }

    /**
     * @param array<string, mixed> $index
     */
    private function write(string $path, array $index): void
    {
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0775, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Cannot create '.\dirname($path));
        }

        ksort($index['modules']);
        ksort($index['themes']);
        file_put_contents($path, json_encode($index, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
    }
}
