<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Joomla;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\NodeDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Migration\DatabaseOptions;

/**
 * Joomla articles become nodes.
 *
 * Joomla splits an article's body in two: introtext is what a listing shows,
 * fulltext is the rest after the read-more break. Joined back together here,
 * because a node has one body and importing only the intro would silently
 * truncate every long article on the site.
 *
 * Its "state" is four-valued — 1 published, 0 unpublished, 2 archived,
 * -2 trashed. Only 1 is published here; the rest arrive as drafts rather than
 * being dropped or made public, for the same reason WordPress's private posts
 * do: publishing what was hidden is a disclosure, discarding it is data loss.
 */
final class JoomlaArticleMigration implements ConfigurableMigrationInterface
{
    public const ID = 'joomla.articles';

    /** @var array<string, string> */
    private readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SlugGenerator $slugGenerator,
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
        return 'Joomla articles';
    }

    public function dependsOn(): array
    {
        return [JoomlaUserMigration::ID, JoomlaCategoryMigration::ID];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all(''),
            MigrationOption::optional('locale', 'Locale the imported content belongs to', 'en'),
            MigrationOption::optional('type', 'CPalius node type to create', 'post'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->slugGenerator, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['content']);

        $content = $database->table('content');

        return new DatabaseSource(
            $database,
            $content.' a',
            'a.id',
            'a.id, a.title, a.alias, a.introtext, a.fulltext, a.state, a.catid, a.created, a.created_by, a.publish_up',
            '',
            300,
            sprintf('Joomla articles in %s', $content),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new NodeDestination(
            $this->entityManager,
            $this->slugGenerator,
            $this->options['type'] ?? 'post',
            $this->options['locale'] ?? 'en',
        );
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $title = trim($row->getString('title'));

        if ($title === '') {
            $title = sprintf('Joomla article %s', $row->sourceId);
        }

        $categoryId = trim($row->getString('catid'));
        $authorId = trim($row->getString('created_by'));

        return $row->withData([
            'title' => $title,
            'slug' => trim($row->getString('alias')),
            'status' => trim($row->getString('state')) === '1' ? Node::STATUS_PUBLISHED : Node::STATUS_DRAFT,
            'locale' => $this->options['locale'] ?? 'en',
            'publishedAt' => $this->publishedAt($row),
            'body' => $this->body($row),
            'categoryIds' => $categoryId === '' || $categoryId === '0'
                ? []
                : $this->lookup->findAll(JoomlaCategoryMigration::ID, [$categoryId]),
            'importedFrom' => 'joomla',
            'joomlaArticleId' => $row->sourceId,
            'joomlaState' => trim($row->getString('state')),
            'joomlaAuthorUserId' => $authorId === '' || $authorId === '0'
                ? ''
                : ($this->lookup->find(JoomlaUserMigration::ID, $authorId) ?? ''),
        ]);
    }

    /**
     * intro + fulltext, which is how Joomla stores one article.
     */
    private function body(MigrationRow $row): string
    {
        $intro = $row->getString('introtext');
        $full = $row->getString('fulltext');

        if (trim($full) === '') {
            return $intro;
        }

        return trim($intro) === '' ? $full : $intro."\n".$full;
    }

    /**
     * publish_up is when it went live; created is when it was written. The
     * first is what a reader means by the date on an article.
     */
    private function publishedAt(MigrationRow $row): string
    {
        foreach (['publish_up', 'created'] as $column) {
            $value = trim($row->getString($column));

            // Joomla writes this for "no date set".
            if ($value !== '' && !str_starts_with($value, '0000-00-00')) {
                return $value;
            }
        }

        return '';
    }

    private function database(): ForeignDatabase
    {
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return DatabaseOptions::connect($this->options, '', $this->entityManager->getConnection());
    }
}
