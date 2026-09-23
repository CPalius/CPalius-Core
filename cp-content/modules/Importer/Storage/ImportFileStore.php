<?php

declare(strict_types=1);

namespace Modules\Importer\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps uploaded exports, and unpacks the archived ones.
 *
 * WHERE THESE GO, AND WHY IT IS NOT public/uploads
 * An export is the most sensitive file an installation will ever hold: every
 * address, every private post, every unpublished draft, often password hashes.
 * Media storage is web-served by design, so putting an export there would
 * publish the old site's user table to anyone who guessed a filename. These
 * live under cp-core/var, which no web server maps.
 *
 * Names are generated, never taken from the upload. A filename is attacker
 * input — it is where "../" comes from — so the operator's name is kept only
 * as a label to show them, and the path is built from random bytes.
 *
 * ARCHIVES
 * A WordPress media import needs a whole uploads folder, and nobody uploads a
 * folder. So a .zip is accepted and unpacked, which brings its own two
 * hazards, both guarded here:
 *   - zip slip: an entry named ../../something escapes the extraction
 *     directory. Every entry is resolved and refused if it lands outside.
 *   - zip bombs: a few kilobytes that expand to fill the disk. Entry count and
 *     total uncompressed size are capped before anything is written.
 */
final class ImportFileStore
{
    /** Extensions accepted for upload. Deliberately short. */
    private const ALLOWED_EXTENSIONS = ['xml', 'csv', 'zip', 'json', 'txt', 'sql', 'gz'];

    private const MAX_UPLOAD_BYTES = 1073741824; // 1 GiB

    private const MAX_ARCHIVE_ENTRIES = 50000;

    private const MAX_ARCHIVE_BYTES = 5368709120; // 5 GiB unpacked

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function store(UploadedFile $file): StoredImport
    {
        if (!$file->isValid()) {
            throw new \RuntimeException(sprintf('Upload failed (%s).', $file->getErrorMessage()));
        }

        $originalName = $this->sanitiseLabel($file->getClientOriginalName());
        $extension = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));

        if (!\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException(sprintf('Cannot import a "%s" file. Upload an export (%s) or a .zip of the uploads folder.', $extension === '' ? 'nameless' : $extension, implode(', ', array_diff(self::ALLOWED_EXTENSIONS, ['zip']))));
        }

        $size = (int) $file->getSize();

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException(sprintf('That file is %s; the limit is %s. Place very large exports on the server and point the field at them instead.', $this->human($size), $this->human(self::MAX_UPLOAD_BYTES)));
        }

        $id = bin2hex(random_bytes(16));
        $base = $this->directory.'/'.$id;

        if (!is_dir($base) && !mkdir($base, 0o775, true) && !is_dir($base)) {
            throw new \RuntimeException(sprintf('Could not create the import directory "%s".', $base));
        }

        try {
            $stored = $extension === 'zip'
                ? $this->unpack($file, $base, $id, $originalName)
                : $this->keep($file, $base, $id, $originalName, $extension);
        } catch (\Throwable $e) {
            // A half-written upload is worse than none: it would show in the
            // list as something an operator could select and then fail on.
            $this->deleteTree($base);

            throw $e;
        }

        $this->writeMeta($base, $stored);

        return $stored;
    }

    /**
     * @return list<StoredImport>
     */
    public function all(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $imports = [];

        foreach ((array) scandir($this->directory) as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $meta = $this->readMeta($this->directory.'/'.$entry);

            if ($meta !== null) {
                $imports[] = $meta;
            }
        }

        usort($imports, static fn (StoredImport $a, StoredImport $b): int => $b->uploadedAt <=> $a->uploadedAt);

        return $imports;
    }

    public function find(string $id): ?StoredImport
    {
        if (!$this->isValidId($id)) {
            return null;
        }

        return $this->readMeta($this->directory.'/'.$id);
    }

    public function delete(string $id): void
    {
        if (!$this->isValidId($id)) {
            throw new \InvalidArgumentException('That is not an upload id.');
        }

        $base = $this->directory.'/'.$id;

        if (is_dir($base)) {
            $this->deleteTree($base);
        }
    }

    private function keep(UploadedFile $file, string $base, string $id, string $originalName, string $extension): StoredImport
    {
        $target = $base.'/export.'.$extension;
        $file->move($base, 'export.'.$extension);

        if ($extension === 'xml') {
            $this->assertParsesAsXml($target, $originalName);
        }

        return new StoredImport(
            $id,
            $originalName,
            StoredImport::KIND_FILE,
            $target,
            (int) (filesize($target) ?: 0),
            new \DateTimeImmutable(),
        );
    }

    private function unpack(UploadedFile $file, string $base, string $id, string $originalName): StoredImport
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The "zip" PHP extension is required to accept archives and is not available.');
        }

        $archive = new \ZipArchive();

        if ($archive->open($file->getPathname()) !== true) {
            throw new \RuntimeException(sprintf('"%s" is not a readable zip archive.', $originalName));
        }

        $contents = $base.'/contents';

        if (!mkdir($contents, 0o775, true) && !is_dir($contents)) {
            throw new \RuntimeException('Could not create the extraction directory.');
        }

        try {
            $this->assertArchiveIsSane($archive, $originalName);
            $this->extract($archive, $contents, $originalName);
        } finally {
            $archive->close();
        }

        return new StoredImport(
            $id,
            $originalName,
            StoredImport::KIND_DIRECTORY,
            $contents,
            $this->treeSize($contents),
            new \DateTimeImmutable(),
        );
    }

    /**
     * Refuses a bomb before a single byte is written.
     */
    private function assertArchiveIsSane(\ZipArchive $archive, string $originalName): void
    {
        if ($archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new \RuntimeException(sprintf('"%s" holds %d entries; the limit is %d.', $originalName, $archive->numFiles, self::MAX_ARCHIVE_ENTRIES));
        }

        $total = 0;

        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $stat = $archive->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $total += (int) $stat['size'];

            if ($total > self::MAX_ARCHIVE_BYTES) {
                throw new \RuntimeException(sprintf('"%s" unpacks to more than %s and was refused.', $originalName, $this->human(self::MAX_ARCHIVE_BYTES)));
            }
        }
    }

    private function extract(\ZipArchive $archive, string $contents, string $originalName): void
    {
        $root = realpath($contents);

        if ($root === false) {
            throw new \RuntimeException('The extraction directory disappeared.');
        }

        $root = str_replace('\\', '/', $root);

        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $name = $archive->getNameIndex($i);

            if (!\is_string($name) || $name === '') {
                continue;
            }

            $relative = $this->safeEntryPath($name, $originalName);

            if ($relative === null) {
                continue;
            }

            $target = $contents.'/'.$relative;

            if (str_ends_with($name, '/')) {
                if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
                    throw new \RuntimeException(sprintf('Could not create "%s" while unpacking.', $relative));
                }

                continue;
            }

            $parent = \dirname($target);

            if (!is_dir($parent) && !mkdir($parent, 0o775, true) && !is_dir($parent)) {
                throw new \RuntimeException(sprintf('Could not create "%s" while unpacking.', $relative));
            }

            $stream = $archive->getStream($name);

            if ($stream === false) {
                continue;
            }

            $out = fopen($target, 'w');

            if ($out === false) {
                fclose($stream);

                throw new \RuntimeException(sprintf('Could not write "%s" while unpacking.', $relative));
            }

            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            // Belt and braces: even with the name checked, confirm the file
            // really landed inside the extraction root before moving on.
            $written = realpath($target);

            if ($written === false || !str_starts_with(str_replace('\\', '/', $written), $root.'/')) {
                @unlink($target);

                throw new \RuntimeException(sprintf('"%s" tried to write outside the archive and was refused.', $originalName));
            }
        }
    }

    /**
     * Normalises an archive entry name, refusing anything that climbs out.
     */
    private function safeEntryPath(string $name, string $originalName): ?string
    {
        $normalised = str_replace('\\', '/', $name);

        // An absolute path or a Windows drive letter is never legitimate here.
        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
            throw new \RuntimeException(sprintf('"%s" contains an absolute path and was refused.', $originalName));
        }

        $segments = [];

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new \RuntimeException(sprintf('"%s" contains a path that climbs out of the archive and was refused.', $originalName));
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * An XML upload that does not parse is refused now rather than at import
     * time, when the operator has already chosen it and started a run.
     */
    private function assertParsesAsXml(string $path, string $originalName): void
    {
        $reader = \XMLReader::open($path, 'UTF-8', \LIBXML_NONET | \LIBXML_NOCDATA);

        if ($reader === false) {
            throw new \RuntimeException(sprintf('"%s" could not be opened as XML.', $originalName));
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $ok = false;

            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $ok = true;
                    break;
                }
            }

            if (!$ok) {
                throw new \RuntimeException(sprintf('"%s" is not valid XML.', $originalName));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }
    }

    private function writeMeta(string $base, StoredImport $import): void
    {
        file_put_contents($base.'/meta.json', json_encode([
            'id' => $import->id,
            'originalName' => $import->originalName,
            'kind' => $import->kind,
            'path' => basename($import->path),
            'size' => $import->size,
            'uploadedAt' => $import->uploadedAt->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    private function readMeta(string $base): ?StoredImport
    {
        $metaFile = $base.'/meta.json';

        if (!is_file($metaFile)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $meta */
            $meta = json_decode((string) file_get_contents($metaFile), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $id = (string) ($meta['id'] ?? '');
        $path = $base.'/'.basename((string) ($meta['path'] ?? ''));

        if (!$this->isValidId($id) || (!is_file($path) && !is_dir($path))) {
            return null;
        }

        try {
            $uploadedAt = new \DateTimeImmutable((string) ($meta['uploadedAt'] ?? 'now'));
        } catch (\Exception) {
            $uploadedAt = new \DateTimeImmutable();
        }

        return new StoredImport(
            $id,
            (string) ($meta['originalName'] ?? $id),
            (string) ($meta['kind'] ?? StoredImport::KIND_FILE),
            $path,
            (int) ($meta['size'] ?? 0),
            $uploadedAt,
        );
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    /**
     * The operator's filename is shown back to them, so it is stripped of
     * anything that would let it act as markup or as a path.
     */
    private function sanitiseLabel(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return mb_substr(trim($name), 0, 120);
    }

    private function treeSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
