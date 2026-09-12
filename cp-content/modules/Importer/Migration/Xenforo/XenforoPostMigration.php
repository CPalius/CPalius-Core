<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Xenforo;

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
use Modules\Importer\Markup\BbCodeConverter;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * XenForo posts become forum posts.
 *
 * This is where the BBCode conversion earns its place: without it an imported
 * board reads as a wall of [b]literal[/b] markup — every word present, none of
 * it legible.
 */
final class XenforoPostMigration implements ConfigurableMigrationInterface
{
    public const ID = 'xenforo.posts';

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
    ) {
        $this->options = $options;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'XenForo posts';
    }

    public function dependsOn(): array
    {
        return [XenforoTopicMigration::ID, XenforoUserMigration::ID];
    }

    public function options(): array
    {
        return DatabaseOptions::all('xf_');
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['post']);

        $post = $database->table('post');

        return new DatabaseSource(
            $database,
            $post.' p',
            'p.post_id',
            'p.post_id, p.thread_id, p.user_id, p.username, p.post_date, p.message, p.message_state',
            '',
            300,
            sprintf('XenForo posts in %s', $post),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumPostDestination($this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $topicId = $this->lookup->find(XenforoTopicMigration::ID, trim($row->getString('thread_id')));

        if ($topicId === null) {
            return null;
        }

        $userId = trim($row->getString('user_id'));

        return $row->withData([
            'topicId' => $topicId,
            'body' => $this->bbcode()->convert($row->getString('message')),
            'posterName' => trim($row->getString('username')),
            'authorUserId' => $userId === '' || $userId === '0'
                ? ''
                : ($this->lookup->find(XenforoUserMigration::ID, $userId) ?? ''),
            'createdAt' => trim($row->getString('post_date')),
            'state' => strtolower(trim($row->getString('message_state'))),
            'importedFrom' => 'xenforo',
            'xenforoPostId' => $row->sourceId,
        ]);
    }

    private function bbcode(): BbCodeConverter
    {
        return $this->bbcode ??= new BbCodeConverter();
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

        return ForeignDatabase::fromOptions($this->options, 'xf_');
    }
}
