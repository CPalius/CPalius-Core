<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Csv;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\NodeDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\CsvSource;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A delimited file becomes content.
 *
 * The core engine has had a CSV reader since Phase A, but nothing registered a
 * migration that used it, so it existed for other code to build on and for
 * nobody to actually run. This is the registration — which is also what puts
 * CSV on the import screen, since that screen lists what is registered rather
 * than what somebody remembered to add to a menu.
 *
 * Columns are mapped by name: title, slug, status, publishedAt are used as
 * they are, and everything else lands in the node's JSON. That is the whole
 * configuration, deliberately — a column-mapping UI is a real feature and a
 * half-built one would be worse than a documented convention.
 */
final class CsvNodeMigration implements ConfigurableMigrationInterface
{
    public const ID = 'csv.nodes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SlugGenerator $slugGenerator,
        private readonly string $file = '',
        private readonly string $idColumn = 'id',
        private readonly string $type = 'post',
        private readonly string $locale = 'en',
        private readonly string $delimiter = ',',
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'CSV rows into content';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            MigrationOption::file('file', 'Path to the CSV file'),
            MigrationOption::optional('idColumn', 'Column holding a stable key for each row', 'id'),
            MigrationOption::optional('type', 'CPalius node type to create', 'post'),
            MigrationOption::optional('locale', 'Locale the imported content belongs to', 'en'),
            MigrationOption::optional('delimiter', 'Field separator, a single character', ','),
        ];
    }

    public function withOptions(array $values): static
    {
        $resolved = MigrationOptionResolver::resolve($this->options(), $values);

        return new static(
            $this->entityManager,
            $this->slugGenerator,
            $resolved['file'],
            $resolved['idColumn'],
            $resolved['type'],
            $resolved['locale'],
            $resolved['delimiter'],
        );
    }

    public function source(): MigrationSourceInterface
    {
        if ($this->file === '') {
            throw new \LogicException('This migration has not been configured; pass -o file=<file.csv>.');
        }

        return new CsvSource($this->file, $this->idColumn, $this->delimiter);
    }

    public function destination(): MigrationDestinationInterface
    {
        return new NodeDestination($this->entityManager, $this->slugGenerator, $this->type, $this->locale);
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $data = $row->data;
        unset($data[$this->idColumn]);

        $status = trim($row->getString('status'));

        return $row->withData([
            ...$data,
            'title' => trim($row->getString('title')),
            'slug' => trim($row->getString('slug')),
            // A spreadsheet is a working document, so anything that does not
            // say "published" in so many words arrives as a draft rather than
            // going live the moment somebody tries an import out.
            'status' => $status === Node::STATUS_PUBLISHED ? Node::STATUS_PUBLISHED : Node::STATUS_DRAFT,
            'locale' => $this->locale,
            'importedFrom' => 'csv',
        ]);
    }
}
