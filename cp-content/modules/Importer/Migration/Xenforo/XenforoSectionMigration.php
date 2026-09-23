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
use Modules\Forum\Migrate\ForumSectionDestination;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * XenForo nodes become forum sections.
 *
 * XenForo keeps its tree in one xf_node table and puts the per-type details in
 * side tables — xf_forum for the ones that hold threads, xf_link_forum for the
 * ones that are just links. Joining them here means the destination sees one
 * shape and does not have to know that.
 *
 * Node types other than Category, Forum and LinkForum (pages, search links,
 * and whatever an add-on introduced) are skipped: they have no forum section
 * to become, and importing them as empty forums would fill the board with
 * clickable nothing.
 */
final class XenforoSectionMigration implements ConfigurableMigrationInterface
{
    public const ID = 'xenforo.sections';

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
        return 'XenForo forums';
    }

    public function dependsOn(): array
    {
        return [];
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
        $database->assertTables(['node']);

        $node = $database->table('node');
        $forum = $database->table('forum');
        $link = $database->table('link_forum');

        return new DatabaseSource(
            $database,
            sprintf(
                '%s n LEFT JOIN %s f ON f.node_id = n.node_id LEFT JOIN %s lf ON lf.node_id = n.node_id',
                $node,
                $forum,
                $link,
            ),
            'n.node_id',
            'n.node_id, n.title, n.description, n.node_type_id, n.parent_node_id, n.display_order, f.allow_posting, lf.link_url',
            "n.node_type_id IN ('Category', 'Forum', 'LinkForum')",
            500,
            sprintf('XenForo forums in %s', $node),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumSectionDestination($this->entityManager, $this->options['locale'] ?? 'en');
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $parentNodeId = trim($row->getString('parent_node_id'));

        return $row->withData([
            // Prefixed so two imported boards cannot collide on "general".
            'code' => 'xenforo-'.$row->sourceId,
            'title' => trim($row->getString('title')),
            'description' => trim($row->getString('description')),
            'locale' => $this->options['locale'] ?? 'en',
            'sortOrder' => trim($row->getString('display_order')),
            'nodeType' => $this->nodeType($row),
            'linkUrl' => trim($row->getString('link_url')),
            'allowTopics' => trim($row->getString('allow_posting', '1')),
            'parentId' => $parentNodeId === '' || $parentNodeId === '0'
                ? ''
                : ($this->lookup->find(self::ID, $parentNodeId) ?? ''),
            'importedFrom' => 'xenforo',
            'xenforoNodeId' => $row->sourceId,
        ]);
    }

    private function nodeType(MigrationRow $row): string
    {
        return match (trim($row->getString('node_type_id'))) {
            'Category' => 'category',
            'LinkForum' => 'link',
            default => 'forum',
        };
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
