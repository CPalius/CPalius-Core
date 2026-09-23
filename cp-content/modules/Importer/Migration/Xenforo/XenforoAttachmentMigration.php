<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Xenforo;

use App\Core\Media\AssetManager;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\AssetDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Migration\DatabaseOptions;
use Modules\Importer\Source\EmptyMigrationSource;
use Modules\Importer\Source\ForumDataFolder;

/**
 * XenForo post attachments become assets, from data/ and internal_data/.
 */
final class XenforoAttachmentMigration implements ConfigurableMigrationInterface
{
    public const ID = 'xenforo.attachments';

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
        return 'XenForo attachments';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return DatabaseOptions::all('xf_');
    }

    public function withOptions(array $values): static
    {
        return new static(
            $this->assetManager,
            $this->entityManager,
            MigrationOptionResolver::resolve($this->options(), $values),
            $this->suppliedDatabase,
        );
    }

    public function source(): MigrationSourceInterface
    {
        if (ForumDataFolder::fromOption($this->options['data'] ?? '') === null) {
            return new EmptyMigrationSource('XenForo attachments (data folder not given)');
        }

        $database = $this->database();
        $database->assertTables(['attachment', 'attachment_data']);

        $attachment = $database->table('attachment');
        $data = $database->table('attachment_data');

        return new DatabaseSource(
            $database,
            sprintf('%s a INNER JOIN %s d ON d.data_id = a.data_id', $attachment, $data),
            'a.attachment_id',
            'a.attachment_id, a.data_id, a.content_type, d.filename, d.file_hash',
            "a.unassociated = 0 AND a.content_type = 'post'",
            200,
            sprintf('XenForo attachments in %s', $attachment),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new AssetDestination($this->assetManager, $this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $files = ForumDataFolder::fromOption($this->options['data'] ?? '');

        if ($files === null) {
            return null;
        }

        $path = $files->xenforoAttachment((int) $row->getString('data_id'), trim($row->getString('file_hash')));

        if ($path === null) {
            return null;
        }

        $name = trim($row->getString('filename'));

        return $row->withData([
            'path' => $path,
            'originalName' => $name !== '' ? $name : basename($path),
            'importedFrom' => 'xenforo',
            'xenforoAttachmentId' => $row->sourceId,
        ]);
    }

    private function database(): ForeignDatabase
    {
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return DatabaseOptions::connect($this->options, 'xf_', $this->entityManager->getConnection());
    }
}
