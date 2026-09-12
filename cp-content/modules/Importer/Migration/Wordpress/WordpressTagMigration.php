<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Wordpress;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\TermDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\WxrReader;
use Modules\Importer\Source\Wordpress\WxrTermSource;

/**
 * WordPress tags become terms in the blog_tag vocabulary.
 *
 * Flat by definition — WordPress tags have no hierarchy — so unlike categories
 * there is no parent to resolve.
 */
final class WordpressTagMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.tags';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $file = '',
        private readonly string $locale = 'en',
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'WordPress tags';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            MigrationOption::file('file', 'Path to the WordPress WXR export file'),
            MigrationOption::optional('locale', 'Locale the imported terms belong to', 'en'),
        ];
    }

    public function withOptions(array $values): static
    {
        $resolved = MigrationOptionResolver::resolve($this->options(), $values);

        return new static($this->entityManager, $resolved['file'], $resolved['locale']);
    }

    public function source(): MigrationSourceInterface
    {
        return new WxrTermSource($this->reader(), WxrTermSource::TAXONOMY_TAG);
    }

    public function destination(): MigrationDestinationInterface
    {
        return new TermDestination($this->entityManager, 'blog_tag', 'Blog tags', $this->locale);
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        return $row->withData([
            'name' => trim($row->getString('name')),
            'slug' => trim($row->getString('slug')),
            'locale' => $this->locale,
            'description' => trim($row->getString('description')),
            'importedFrom' => 'wordpress',
            'wordpressTermId' => trim($row->getString('wpTermId')),
        ]);
    }

    private function reader(): WxrReader
    {
        if ($this->file === '') {
            throw new \LogicException('This migration has not been configured; pass -o file=<export.xml>.');
        }

        return new WxrReader($this->file);
    }
}
