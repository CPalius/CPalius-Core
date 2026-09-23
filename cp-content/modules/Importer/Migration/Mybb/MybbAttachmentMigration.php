<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Mybb;

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
 * MyBB post attachments become assets, from uploads/.
 */
final class MybbAttachmentMigration implements ConfigurableMigrationInterface
{
    public const ID = 'mybb.attachments';

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
        return 'MyBB attachments';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return DatabaseOptions::all('mybb_');
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
            return new EmptyMigrationSource('MyBB attachments (uploads folder not given)');
        }

        $database = $this->database();
        $database->assertTables(['attachments']);

        $table = $database->table('attachments');

        return new DatabaseSource(
            $database,
            $table.' a',
            'a.aid',
            'a.aid, a.pid, a.filename, a.attachname, a.visible',
            'a.visible = 1 AND a.pid > 0',
            200,
            sprintf('MyBB attachments in %s', $table),
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

        $path = $files->mybbAttachment(trim($row->getString('attachname')));

        if ($path === null) {
            return null;
        }

        $name = trim($row->getString('filename'));

        return $row->withData([
            'path' => $path,
            'originalName' => $name !== '' ? $name : basename($path),
            'importedFrom' => 'mybb',
            'mybbAttachmentId' => $row->sourceId,
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

        return DatabaseOptions::connect($this->options, 'mybb_', $this->entityManager->getConnection());
    }
}
