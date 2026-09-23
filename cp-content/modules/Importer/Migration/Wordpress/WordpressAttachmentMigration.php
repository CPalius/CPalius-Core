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
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\UploadsResolver;
use Modules\Importer\Source\Wordpress\WordpressOrigin;

/**
 * WordPress attachments become assets.
 *
 * A file that is not in the uploads directory is a FAILURE, not a skip.
 */
final class WordpressAttachmentMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.attachments';

    /** @var array<string, string> */
    private readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly EntityManagerInterface $entityManager,
        array $options = [],
        private readonly ?ForeignDatabase $suppliedDatabase = null,
    ) {
        $this->options = $options;
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
            ...WordpressOrigin::commonOptions(),
            MigrationOption::directory('uploads', 'The old site\'s wp-content/uploads folder — upload it as a .zip, or point at a path on the server', false),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->assetManager, $this->entityManager, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        if (trim($this->options['uploads'] ?? '') === '') {
            return new class implements MigrationSourceInterface {
                public function describe(): string
                {
                    return 'WordPress attachments (uploads folder not given)';
                }

                public function rows(): iterable
                {
                    return [];
                }

                public function count(): int
                {
                    return 0;
                }
            };
        }

        return $this->origin()->attachments();
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
        $uploads = trim($this->options['uploads'] ?? '');

        if ($uploads === '') {
            throw new \RuntimeException('Attachments need the old wp-content/uploads folder. Upload it as a zip or set the uploads path. Posts and users can still import without it.');
        }

        return new UploadsResolver($uploads);
    }

    private function origin(): WordpressOrigin
    {
        return new WordpressOrigin($this->entityManager, $this->options, $this->suppliedDatabase);
    }
}
