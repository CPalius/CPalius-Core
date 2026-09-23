<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;

/**
 * wp_posts of one type, with terms and postmeta attached the way WXR items carry them.
 */
final class WordpressPostDbSource implements MigrationSourceInterface
{
    private const NEVER_IMPORT_STATUS = ['auto-draft', 'trash', 'inherit'];

    public function __construct(
        private readonly ForeignDatabase $database,
        private readonly string $postType = 'post',
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress "%s" rows in %s', $this->postType, $this->database->table('posts'));
    }

    public function rows(): iterable
    {
        $posts = $this->database->table('posts');
        $users = $this->database->table('users');
        $hasUsers = $this->database->hasTable('users');
        $statusList = implode(',', array_map(static fn (string $s): string => "'".$s."'", self::NEVER_IMPORT_STATUS));
        $type = str_replace("'", "''", $this->postType);

        $from = $hasUsers
            ? sprintf('%s p LEFT JOIN %s u ON u.ID = p.post_author', $posts, $users)
            : $posts.' p';
        $select = $hasUsers
            ? 'p.ID, p.post_title, p.post_name, p.post_content, p.post_excerpt, p.post_status, p.post_date, p.guid, p.post_parent, p.menu_order, u.user_login'
            : 'p.ID, p.post_title, p.post_name, p.post_content, p.post_excerpt, p.post_status, p.post_date, p.guid, p.post_parent, p.menu_order';

        $inner = new DatabaseSource(
            $this->database,
            $from,
            'p.ID',
            $select,
            sprintf("p.post_type = '%s' AND p.post_status NOT IN (%s)", $type, $statusList),
            200,
            $this->describe(),
        );

        $buffer = [];
        foreach ($inner->rows() as $row) {
            $buffer[] = $row;
            if (\count($buffer) >= 200) {
                yield from $this->hydrate($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield from $this->hydrate($buffer);
        }
    }

    public function count(): ?int
    {
        try {
            return (int) $this->database->connection()->fetchOne(
                sprintf(
                    "SELECT COUNT(*) FROM %s WHERE post_type = ? AND post_status NOT IN ('auto-draft', 'trash', 'inherit')",
                    $this->database->table('posts'),
                ),
                [$this->postType],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<MigrationRow> $rows
     *
     * @return iterable<MigrationRow>
     */
    private function hydrate(array $rows): iterable
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->getString('ID');
        }

        $meta = $this->metaFor($ids);
        $terms = $this->termsFor($ids);

        foreach ($rows as $row) {
            $id = $row->getString('ID');

            if ($id === '') {
                continue;
            }

            $published = trim($row->getString('post_date'));

            yield new MigrationRow($id, [
                'title' => $row->getString('post_title'),
                'slug' => $row->getString('post_name'),
                'content' => $row->getString('post_content'),
                'excerpt' => $row->getString('post_excerpt'),
                'status' => $row->getString('post_status'),
                'creator' => $row->getString('user_login'),
                'publishedAt' => $published,
                'link' => '',
                'guid' => $row->getString('guid'),
                'parentId' => $row->getString('post_parent'),
                'menuOrder' => $row->getString('menu_order'),
                'isSticky' => '0',
                'categorySlugs' => $terms[$id][WxrTermSource::TAXONOMY_CATEGORY] ?? [],
                'tagSlugs' => $terms[$id][WxrTermSource::TAXONOMY_TAG] ?? [],
                'meta' => $meta[$id] ?? [],
            ]);
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, string>>
     */
    private function metaFor(array $ids): array
    {
        if ($ids === [] || !$this->database->hasTable('postmeta')) {
            return [];
        }

        $rows = $this->database->connection()->fetchAllAssociative(
            sprintf(
                'SELECT post_id, meta_key, meta_value FROM %s WHERE post_id IN (%s)',
                $this->database->table('postmeta'),
                implode(',', array_map('intval', $ids)),
            ),
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, list<string>>>
     */
    private function termsFor(array $ids): array
    {
        if ($ids === [] || !$this->database->hasTable('term_relationships') || !$this->database->hasTable('term_taxonomy') || !$this->database->hasTable('terms')) {
            return [];
        }

        $rows = $this->database->connection()->fetchAllAssociative(
            sprintf(
                'SELECT tr.object_id, t.slug, tt.taxonomy
                 FROM %s tr
                 INNER JOIN %s tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN %s t ON t.term_id = tt.term_id
                 WHERE tr.object_id IN (%s)',
                $this->database->table('term_relationships'),
                $this->database->table('term_taxonomy'),
                $this->database->table('terms'),
                implode(',', array_map('intval', $ids)),
            ),
        );

        $out = [];
        foreach ($rows as $row) {
            $slug = trim((string) $row['slug']);
            if ($slug === '') {
                continue;
            }
            $out[(string) $row['object_id']][(string) $row['taxonomy']][] = $slug;
        }

        return $out;
    }
}
