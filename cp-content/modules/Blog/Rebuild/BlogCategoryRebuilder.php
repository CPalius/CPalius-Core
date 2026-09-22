<?php

declare(strict_types=1);

namespace Modules\Blog\Rebuild;

use App\Core\Rebuild\RebuilderInterface;
use App\Core\Taxonomy\DefaultVocabularies;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Write published-post counts onto each blog_category term (data.post_count).
 */
final class BlogCategoryRebuilder implements RebuilderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getId(): string
    {
        return 'blog.categories';
    }

    public function getName(): string
    {
        return 'blog.rebuild.categories';
    }

    public function getDescription(): string
    {
        return 'blog.rebuild.categories_desc';
    }

    public function getBatchSize(): int
    {
        return 100;
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getTotal(): int
    {
        $conn = $this->entityManager->getConnection();

        return (int) $conn->fetchOne(
            'SELECT COUNT(*)
             FROM cp_terms t
             INNER JOIN cp_vocabularies v ON v.id = t.vocabulary_id
             WHERE v.machine_name = :vocab',
            ['vocab' => DefaultVocabularies::BLOG_CATEGORY],
        );
    }

    public function isStudioVisible(): bool
    {
        return true;
    }

    public function rebuild(int $offset, int $limit): int
    {
        $conn = $this->entityManager->getConnection();
        $rows = $conn->fetchAllAssociative(sprintf(
            'SELECT t.id AS id,
                    (SELECT COUNT(*)
                     FROM cp_node_categories nc
                     INNER JOIN cp_nodes n ON n.id = nc.node_id
                     WHERE nc.category_id = t.id
                       AND n.type = %s
                       AND n.status = %s
                       AND n.deleted_at IS NULL) AS post_count
             FROM cp_terms t
             INNER JOIN cp_vocabularies v ON v.id = t.vocabulary_id
             WHERE v.machine_name = %s
             ORDER BY t.id ASC
             LIMIT %d OFFSET %d',
            $conn->quote('post'),
            $conn->quote(Node::STATUS_PUBLISHED),
            $conn->quote(DefaultVocabularies::BLOG_CATEGORY),
            max(0, $limit),
            max(0, $offset),
        ));

        $visited = 0;
        foreach ($rows as $row) {
            ++$visited;
            $term = $this->entityManager->find(Term::class, (int) $row['id']);
            if (!$term instanceof Term) {
                continue;
            }
            $data = $term->getData();
            $data['post_count'] = (int) $row['post_count'];
            $term->setFieldableData($data);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $visited;
    }
}
