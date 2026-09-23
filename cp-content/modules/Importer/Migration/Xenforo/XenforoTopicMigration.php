<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Xenforo;

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
 * XenForo threads become forum topics.
 *
 * A thread whose forum was not imported is SKIPPED rather than failed: the
 * section migration deliberately leaves out node types that are not forums,
 * and threads under them have nowhere to go. One failure per orphan would bury
 * the real problems.
 */
final class XenforoTopicMigration implements ConfigurableMigrationInterface
{
    public const ID = 'xenforo.topics';

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
        return 'XenForo threads';
    }

    public function dependsOn(): array
    {
        return [XenforoSectionMigration::ID, XenforoUserMigration::ID];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all('xf_'),
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
        $database->assertTables(['thread']);

        $thread = $database->table('thread');

        return new DatabaseSource(
            $database,
            $thread.' t',
            't.thread_id',
            't.thread_id, t.node_id, t.title, t.user_id, t.username, t.post_date, t.last_post_date, t.view_count, t.reply_count, t.sticky, t.discussion_open, t.discussion_state',
            '',
            500,
            sprintf('XenForo threads in %s', $thread),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumTopicDestination($this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $sectionId = $this->lookup->find(XenforoSectionMigration::ID, trim($row->getString('node_id')));

        if ($sectionId === null) {
            return null;
        }

        $userId = trim($row->getString('user_id'));

        return $row->withData([
            'sectionId' => $sectionId,
            'title' => trim($row->getString('title')),
            // Carried so a second run with a different locale updates topics
            // in place instead of leaving them on the language first chosen.
            'locale' => $this->options['locale'] ?? 'en',
            'posterName' => trim($row->getString('username')),
            'authorUserId' => $userId === '' || $userId === '0'
                ? ''
                : ($this->lookup->find(XenforoUserMigration::ID, $userId) ?? ''),
            'createdAt' => trim($row->getString('post_date')),
            'lastPostAt' => trim($row->getString('last_post_date')),
            'viewCount' => trim($row->getString('view_count')),
            'postCount' => trim($row->getString('reply_count')),
            'sticky' => trim($row->getString('sticky')) === '1' ? '1' : '0',
            // XenForo stores "is the discussion open"; CPalius stores "is it
            // locked", so this is deliberately the inverse rather than a copy.
            'locked' => trim($row->getString('discussion_open')) === '1' ? '0' : '1',
            'state' => strtolower(trim($row->getString('discussion_state'))),
            'importedFrom' => 'xenforo',
            'xenforoThreadId' => $row->sourceId,
        ]);
    }

    private function database(): ForeignDatabase
    {
        // A caller that already has a connection — a test, or a future runner
        // that opens one connection for the whole board — supplies it here.
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return DatabaseOptions::connect($this->options, 'xf_', $this->entityManager->getConnection());
    }
}
