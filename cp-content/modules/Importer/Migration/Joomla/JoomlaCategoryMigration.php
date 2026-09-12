<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Joomla;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\TermDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * Joomla content categories become taxonomy terms.
 *
 * Joomla keeps categories for every extension in one table and tells them apart
 * with "extension"; only com_content ones are articles' categories, and
 * importing the rest would fill the vocabulary with contact groups and banner
 * folders.
 *
 * Its root is a real row (id 1, parent_id 0) that exists to anchor the nested
 * set rather than to be a category. Importing it would put every real category
 * under a term called "ROOT".
 */
final class JoomlaCategoryMigration implements ConfigurableMigrationInterface
{
    public const ID = 'joomla.categories';

    private const JOOMLA_ROOT_ID = '1';

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
        return 'Joomla categories';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all(''),
            MigrationOption::optional('locale', 'Locale the imported content belongs to', 'en'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['categories']);

        $categories = $database->table('categories');

        return new DatabaseSource(
            $database,
            $categories.' c',
            'c.id',
            'c.id, c.parent_id, c.title, c.alias, c.description, c.published, c.extension',
            "c.extension = 'com_content' AND c.id <> ".self::JOOMLA_ROOT_ID,
            500,
            sprintf('Joomla categories in %s', $categories),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new TermDestination(
            $this->entityManager,
            'joomla_category',
            'Joomla categories',
            $this->options['locale'] ?? 'en',
        );
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $parentId = trim($row->getString('parent_id'));

        return $row->withData([
            'name' => trim($row->getString('title')),
            'slug' => trim($row->getString('alias')),
            'locale' => $this->options['locale'] ?? 'en',
            'description' => trim($row->getString('description')),
            // The Joomla root is not imported, so a category directly under it
            // is a root category here.
            'parentId' => $parentId === '' || $parentId === '0' || $parentId === self::JOOMLA_ROOT_ID
                ? ''
                : ($this->lookup->find(self::ID, $parentId) ?? ''),
            'importedFrom' => 'joomla',
            'joomlaCategoryId' => $row->sourceId,
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

        return ForeignDatabase::fromOptions($this->options);
    }
}
