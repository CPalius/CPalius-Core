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
use Modules\Forum\Migrate\ForumSectionDestination;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * MyBB forums and categories become forum sections.
 *
 * MyBB keeps both in one table and tells them apart with a single character in
 * "type": "c" for a category, "f" for a forum that holds threads. A "linkto"
 * value turns a forum into a link, whatever its type says.
 */
final class MybbSectionMigration implements ConfigurableMigrationInterface
{
    public const ID = 'mybb.sections';

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
        return 'MyBB forums';
    }

    public function dependsOn(): array
    {
        return [];
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
        $database->assertTables(['forums']);

        $forums = $database->table('forums');

        return new DatabaseSource(
            $database,
            $forums.' f',
            'f.fid',
            'f.fid, f.pid, f.name, f.description, f.type, f.disporder, f.open, f.linkto',
            '',
            500,
            sprintf('MyBB forums in %s', $forums),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumSectionDestination($this->entityManager, $this->options['locale'] ?? 'en');
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $parentId = trim($row->getString('pid'));
        $linkUrl = trim($row->getString('linkto'));

        return $row->withData([
            'code' => 'mybb-'.$row->sourceId,
            'title' => trim($row->getString('name')),
            'description' => trim($row->getString('description')),
            'locale' => $this->options['locale'] ?? 'en',
            'sortOrder' => trim($row->getString('disporder')),
            'nodeType' => $this->nodeType($row, $linkUrl),
            'linkUrl' => $linkUrl,
            'allowTopics' => trim($row->getString('open', '1')),
            'parentId' => $parentId === '' || $parentId === '0'
                ? ''
                : ($this->lookup->find(self::ID, $parentId) ?? ''),
            'importedFrom' => 'mybb',
            'mybbForumId' => $row->sourceId,
        ]);
    }

    /**
     * A link wins over the type: MyBB stores a link as an ordinary forum that
     * happens to have a destination, and importing it as a forum would produce
     * an empty board section where a link belongs.
     */
    private function nodeType(MigrationRow $row, string $linkUrl): string
    {
        if ($linkUrl !== '') {
            return 'link';
        }

        return strtolower(trim($row->getString('type'))) === 'c' ? 'category' : 'forum';
    }

    private function database(): ForeignDatabase
    {
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return ForeignDatabase::fromOptions($this->options, 'mybb_');
    }
}
