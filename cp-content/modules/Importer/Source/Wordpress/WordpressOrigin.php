<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * How a WordPress import is fed: WXR XML, a .sql dump, or a live MySQL.
 *
 * The WXR file (Tools → Export) is still the documented path. Operators also
 * arrive with a phpMyAdmin dump or the old database sitting on another host —
 * the same two ways XenForo already accepts. Putting a .sql in the WXR field
 * used to open XMLReader on a SQL file and report "Document is empty".
 */
final class WordpressOrigin
{
    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $options = [],
        private readonly ?ForeignDatabase $suppliedDatabase = null,
    ) {
    }

    /**
     * @return list<MigrationOption>
     */
    public static function commonOptions(): array
    {
        return [
            MigrationOption::optional(
                'file',
                'WordPress WXR (.xml) from Tools → Export. Not a .sql dump — that belongs under SQL dump, or pick the uploaded .sql there.',
                null,
                MigrationOption::KIND_FILE,
            ),
            ...DatabaseOptions::all('wp_'),
        ];
    }

    public function isDatabase(): bool
    {
        return $this->suppliedDatabase !== null
            || $this->dumpPath() !== ''
            || trim($this->options['dbName'] ?? '') !== '';
    }

    public function database(): ForeignDatabase
    {
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        $options = $this->options;
        $dump = $this->dumpPath();

        if ($dump !== '') {
            $options['sqlDump'] = $dump;
        }

        return DatabaseOptions::connect($options, 'wp_', $this->entityManager->getConnection());
    }

    public function reader(): WxrReader
    {
        $file = trim($this->options['file'] ?? '');

        if ($file === '') {
            throw new \RuntimeException('A WordPress import needs a WXR .xml (Tools → Export), an uploaded .sql dump, or the remote database fields.');
        }

        if (self::looksLikeSqlDump($file)) {
            throw new \RuntimeException('That file is a SQL dump, not a WordPress WXR export. Leave the WXR field empty and choose it as the SQL dump, or fill in the remote database fields.');
        }

        return new WxrReader($file);
    }

    public function authors(): MigrationSourceInterface
    {
        $this->assertReady('users');

        return $this->isDatabase()
            ? new WordpressUserSource($this->database())
            : new WxrAuthorSource($this->reader());
    }

    public function terms(string $taxonomy): MigrationSourceInterface
    {
        $this->assertReady('terms');

        return $this->isDatabase()
            ? new WordpressTermDbSource($this->database(), $taxonomy)
            : new WxrTermSource($this->reader(), $taxonomy);
    }

    public function posts(string $postType): MigrationSourceInterface
    {
        $this->assertReady('posts');

        return $this->isDatabase()
            ? new WordpressPostDbSource($this->database(), $postType)
            : new WxrPostSource($this->reader(), $postType);
    }

    public function comments(string $postType): MigrationSourceInterface
    {
        $this->assertReady('comments');

        return $this->isDatabase()
            ? new WordpressCommentDbSource($this->database(), $postType)
            : new WxrCommentSource($this->reader(), $postType);
    }

    public function attachments(): MigrationSourceInterface
    {
        $this->assertReady('posts');

        return $this->isDatabase()
            ? new WordpressAttachmentDbSource($this->database())
            : new WxrAttachmentSource($this->reader());
    }

    private function assertReady(string $table): void
    {
        if ($this->isDatabase()) {
            $this->database()->assertTables([$table]);
        }
    }

    public function dumpPath(): string
    {
        $dump = trim($this->options['sqlDump'] ?? '');

        if ($dump !== '') {
            return $dump;
        }

        $file = trim($this->options['file'] ?? '');

        return $file !== '' && self::looksLikeSqlDump($file) ? $file : '';
    }

    public static function looksLikeSqlDump(string $path): bool
    {
        $lower = strtolower($path);

        if (str_ends_with($lower, '.sql') || str_ends_with($lower, '.sql.gz')) {
            return true;
        }

        $head = self::head($path, 800);
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head;
        $head = ltrim($head);

        if ($head === '') {
            return pathinfo($path, \PATHINFO_EXTENSION) !== 'xml';
        }

        return !str_starts_with($head, '<') && !str_starts_with($head, '<?xml');
    }

    private static function head(string $path, int $bytes): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        if (str_ends_with(strtolower($path), '.gz')) {
            $handle = @gzopen($path, 'rb');

            if ($handle === false) {
                return '';
            }

            $head = (string) gzread($handle, $bytes);
            gzclose($handle);

            return $head;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, $bytes);
        fclose($handle);

        return $head;
    }
}
