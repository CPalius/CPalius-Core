<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Mybb;

use App\Core\Media\AssetManager;
use App\Core\Media\AssetUrlGenerator;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Migrate\ForumPostDestination;
use Modules\Forum\Markup\BbCodeConverter;
use Modules\Importer\Markup\ForumAttachMarkup;
use Modules\Importer\Migration\DatabaseOptions;
use Modules\Importer\Source\LocalAssetIntake;

/**
 * MyBB posts become forum posts.
 */
final class MybbPostMigration implements ConfigurableMigrationInterface
{
    public const ID = 'mybb.posts';

    /** @var array<string, string> */
    private readonly array $options;

    private ?BbCodeConverter $bbcode = null;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MigrationLookup $lookup,
        array $options = [],
        private readonly ?ForeignDatabase $suppliedDatabase = null,
        private readonly ?AssetManager $assetManager = null,
        private readonly ?AssetUrlGenerator $urls = null,
    ) {
        $this->options = $options;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'MyBB posts';
    }

    public function dependsOn(): array
    {
        return [MybbTopicMigration::ID, MybbUserMigration::ID, MybbAttachmentMigration::ID];
    }

    public function options(): array
    {
        return DatabaseOptions::all('mybb_');
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase, $this->assetManager, $this->urls);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['posts']);

        $posts = $database->table('posts');

        return new DatabaseSource(
            $database,
            $posts.' p',
            'p.pid',
            'p.pid, p.tid, p.uid, p.username, p.dateline, p.message, p.visible',
            '',
            300,
            sprintf('MyBB posts in %s', $posts),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumPostDestination($this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $topicId = $this->lookup->find(MybbTopicMigration::ID, trim($row->getString('tid')));

        if ($topicId === null) {
            return null;
        }

        $userId = trim($row->getString('uid'));

        return $row->withData([
            'topicId' => $topicId,
            'body' => $this->bbcode()->convert($this->attachMarkup()->rewrite($row->getString('message'))),
            'posterName' => trim($row->getString('username')),
            'authorUserId' => $userId === '' || $userId === '0'
                ? ''
                : ($this->lookup->find(MybbUserMigration::ID, $userId) ?? ''),
            'createdAt' => trim($row->getString('dateline')),
            'state' => MybbVisibility::toState($row->getString('visible')),
            'importedFrom' => 'mybb',
            'mybbPostId' => $row->sourceId,
        ]);
    }

    private function bbcode(): BbCodeConverter
    {
        return $this->bbcode ??= new BbCodeConverter();
    }

    private function attachMarkup(): ForumAttachMarkup
    {
        return new ForumAttachMarkup(
            $this->lookup,
            new LocalAssetIntake($this->entityManager, $this->assetManager, $this->urls),
            MybbAttachmentMigration::ID,
        );
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
