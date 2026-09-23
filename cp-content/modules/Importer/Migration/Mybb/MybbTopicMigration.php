<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Mybb;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Migrate\ForumTopicDestination;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * MyBB threads become forum topics.
 *
 * MyBB's "visible" column is a three-state: 1 shown, 0 waiting for a moderator,
 * -1 soft-deleted. All three are carried across as the corresponding state
 * rather than collapsed — a board's moderation queue is part of its history,
 * and silently publishing what was held is the one outcome that cannot be
 * undone by looking at it afterwards.
 */
final class MybbTopicMigration implements ConfigurableMigrationInterface
{
    public const ID = 'mybb.topics';

    /** @var array<string, string> */
    private readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MigrationLookup $lookup,
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
        return 'MyBB threads';
    }

    public function dependsOn(): array
    {
        return [MybbSectionMigration::ID, MybbUserMigration::ID];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all('mybb_'),
            MigrationOption::optional('locale', 'Locale the imported board belongs to', 'en'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['threads']);

        $threads = $database->table('threads');

        return new DatabaseSource(
            $database,
            $threads.' t',
            't.tid',
            't.tid, t.fid, t.subject, t.uid, t.username, t.dateline, t.lastpost, t.views, t.replies, t.sticky, t.closed, t.visible',
            '',
            500,
            sprintf('MyBB threads in %s', $threads),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumTopicDestination($this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $sectionId = $this->lookup->find(MybbSectionMigration::ID, trim($row->getString('fid')));

        if ($sectionId === null) {
            return null;
        }

        $userId = trim($row->getString('uid'));

        return $row->withData([
            'sectionId' => $sectionId,
            'title' => trim($row->getString('subject')),
            'locale' => $this->options['locale'] ?? 'en',
            'posterName' => trim($row->getString('username')),
            'authorUserId' => $userId === '' || $userId === '0'
                ? ''
                : ($this->lookup->find(MybbUserMigration::ID, $userId) ?? ''),
            'createdAt' => trim($row->getString('dateline')),
            'lastPostAt' => trim($row->getString('lastpost')),
            'viewCount' => trim($row->getString('views')),
            'postCount' => trim($row->getString('replies')),
            'sticky' => trim($row->getString('sticky')) === '1' ? '1' : '0',
            'locked' => $this->locked($row),
            'state' => MybbVisibility::toState($row->getString('visible')),
            'importedFrom' => 'mybb',
            'mybbThreadId' => $row->sourceId,
        ]);
    }

    /**
     * MyBB writes "moved|tid" into closed for a redirect left behind by a moved
     * thread, so this cannot be compared to "1" alone.
     */
    private function locked(MigrationRow $row): string
    {
        $closed = trim($row->getString('closed'));

        return $closed === '' || $closed === '0' ? '0' : '1';
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
