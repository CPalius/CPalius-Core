<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Wordpress;

use App\Core\Media\AssetManager;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\AssetDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\UploadsResolver;
use Modules\Importer\Source\Wordpress\WxrAttachmentSource;
use Modules\Importer\Source\Wordpress\WxrReader;

/**
 * WordPress attachments become assets.
 *
 * Runs before posts, because a post's body still points at the old site's URLs
 * until the assets exist to point at instead.
 *
 * A file that is not in the uploads directory is a FAILURE, not a skip. The
 * operator needs the list: a silently missing image is discovered months later
 * by a reader, and by then nobody remembers which export it came from. The
 * runner reports failures by source id and keeps going, so one missing file
 * costs one image rather than the import.
 *
 * Some uploads are refused by the asset allowlist — SVG most commonly, which
 * CPalius does not accept because it is a script-carrying format. Those also
 * surface as failures with the reason attached, which is the honest outcome:
 * the file genuinely did not come across.
 */
final class WordpressAttachmentMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.attachments';

    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $file = '',
        private readonly string $uploads = '',
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'WordPress attachments';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            MigrationOption::required('file', 'Path to the WordPress WXR export file'),
            MigrationOption::required('uploads', 'Path to your copy of the old site\'s wp-content/uploads directory'),
        ];
    }

    public function withOptions(array $values): static
    {
        $resolved = MigrationOptionResolver::resolve($this->options(), $values);

        return new static($this->assetManager, $this->entityManager, $resolved['file'], $resolved['uploads']);
    }

    public function source(): MigrationSourceInterface
    {
        return new WxrAttachmentSource($this->reader());
    }

    public function destination(): MigrationDestinationInterface
    {
        return new AssetDestination($this->assetManager, $this->entityManager);
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $url = trim($row->getString('attachmentUrl'));
        $resolver = $this->resolver();
        $path = $resolver->resolve($url);

        if ($path === null) {
            throw new \RuntimeException(sprintf('No file for "%s" under %s. Copy the old site\'s uploads folder there, or exclude this attachment.', $url, $resolver->root()));
        }

        return $row->withData([
            'path' => $path,
            'originalName' => basename($path),
            'alt' => trim($row->getString('alt')),
            'caption' => trim($row->getString('caption')),
            'title' => trim($row->getString('title')),
            'sourceUrl' => $url,
            'importedFrom' => 'wordpress',
        ]);
    }

    private function resolver(): UploadsResolver
    {
        if ($this->uploads === '') {
            throw new \LogicException('This migration has not been configured; pass -o uploads=<path to wp-content/uploads>.');
        }

        return new UploadsResolver($this->uploads);
    }

    private function reader(): WxrReader
    {
        if ($this->file === '') {
            throw new \LogicException('This migration has not been configured; pass -o file=<export.xml>.');
        }

        return new WxrReader($this->file);
    }
}
