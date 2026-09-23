<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;

/**
 * wp_terms + wp_term_taxonomy, keyed by slug like WxrTermSource.
 */
final class WordpressTermDbSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly ForeignDatabase $database,
        private readonly string $taxonomy,
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress "%s" terms in %s', $this->taxonomy, $this->database->table('terms'));
    }

    public function rows(): iterable
    {
        $terms = $this->database->table('terms');
        $tax = $this->database->table('term_taxonomy');

        $inner = new DatabaseSource(
            $this->database,
            sprintf('%s t INNER JOIN %s tt ON tt.term_id = t.term_id LEFT JOIN %s p ON p.term_id = tt.parent', $terms, $tax, $terms),
            't.term_id',
            't.term_id, t.name, t.slug, tt.description, p.slug AS parent_slug',
            sprintf('tt.taxonomy = \'%s\'', $this->escapedTaxonomy()),
            500,
            $this->describe(),
        );

        foreach ($inner->rows() as $row) {
            $slug = trim($row->getString('slug'));
            $name = trim($row->getString('name'));

            if ($slug === '') {
                $slug = $name;
            }

            if ($slug === '') {
                continue;
            }

            yield new MigrationRow($slug, [
                'name' => $name !== '' ? $name : $slug,
                'slug' => $slug,
                'description' => $row->getString('description'),
                'parentSlug' => trim($row->getString('parent_slug')),
                'wpTermId' => $row->getString('term_id'),
            ]);
        }
    }

    public function count(): ?int
    {
        try {
            return (int) $this->database->connection()->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s tt WHERE tt.taxonomy = ?',
                    $this->database->table('term_taxonomy'),
                ),
                [$this->taxonomy],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function escapedTaxonomy(): string
    {
        return str_replace("'", "''", $this->taxonomy);
    }
}
