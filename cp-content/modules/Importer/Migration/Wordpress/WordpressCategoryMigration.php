<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Wordpress;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\TermDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\WordpressOrigin;
use Modules\Importer\Source\Wordpress\WxrTermSource;

/**
 * WordPress categories become terms in the blog_category vocabulary.
 *
 * Parents are resolved through this migration's own map: a category's parent is
 * another category, so the lookup is against itself. That works because an
 * export lists parents before children often enough to matter, and when it does
 * not the child lands at the root and a second run — which costs nothing,
 * because unchanged rows are skipped — puts it in place.
 */
final class WordpressCategoryMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.categories';

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
        return 'WordPress categories';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...WordpressOrigin::commonOptions(),
            MigrationOption::optional('locale', 'Locale the imported terms belong to', 'en'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        return $this->origin()->terms(WxrTermSource::TAXONOMY_CATEGORY);
    }

    public function destination(): MigrationDestinationInterface
    {
        return new TermDestination($this->entityManager, 'blog_category', 'Blog categories', $this->options['locale'] ?? 'en');
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $parentSlug = trim($row->getString('parentSlug'));

        return $row->withData([
            'name' => trim($row->getString('name')),
            'slug' => trim($row->getString('slug')),
            'locale' => $this->options['locale'] ?? 'en',
            'description' => trim($row->getString('description')),
            'parentId' => $parentSlug === '' ? '' : ($this->lookup->find(self::ID, $parentSlug) ?? ''),
            'importedFrom' => 'wordpress',
            'wordpressTermId' => trim($row->getString('wpTermId')),
        ]);
    }

    private function origin(): WordpressOrigin
    {
        return new WordpressOrigin($this->entityManager, $this->options, $this->suppliedDatabase);
    }
}
