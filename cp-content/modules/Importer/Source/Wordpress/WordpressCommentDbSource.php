<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;

/**
 * wp_comments on posts of one type. Pingbacks and trackbacks stay out.
 */
final class WordpressCommentDbSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly ForeignDatabase $database,
        private readonly string $postType = 'post',
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress comments on "%s" in %s', $this->postType, $this->database->table('comments'));
    }

    public function rows(): iterable
    {
        $comments = $this->database->table('comments');
        $posts = $this->database->table('posts');
        $type = str_replace("'", "''", $this->postType);

        $inner = new DatabaseSource(
            $this->database,
            sprintf('%s c INNER JOIN %s p ON p.ID = c.comment_post_ID', $comments, $posts),
            'c.comment_ID',
            'c.comment_ID, c.comment_post_ID, c.comment_author, c.comment_author_email, c.comment_author_url, c.comment_content, c.comment_approved, c.comment_parent, c.comment_date_gmt, c.comment_type',
            sprintf("p.post_type = '%s' AND c.comment_type NOT IN ('pingback', 'trackback')", $type),
            300,
            $this->describe(),
        );

        foreach ($inner->rows() as $row) {
            $id = $row->getString('comment_ID');

            if ($id === '') {
                continue;
            }

            yield new MigrationRow($id, [
                'wordpressPostId' => $row->getString('comment_post_ID'),
                'authorName' => $row->getString('comment_author'),
                'authorEmail' => $row->getString('comment_author_email'),
                'authorUrl' => $row->getString('comment_author_url'),
                'body' => $row->getString('comment_content'),
                'approved' => $row->getString('comment_approved'),
                'parentCommentId' => $row->getString('comment_parent'),
                'dateGmt' => $row->getString('comment_date_gmt'),
            ]);
        }
    }

    public function count(): ?int
    {
        try {
            return (int) $this->database->connection()->fetchOne(
                sprintf(
                    "SELECT COUNT(*) FROM %s c INNER JOIN %s p ON p.ID = c.comment_post_ID
                     WHERE p.post_type = ? AND c.comment_type NOT IN ('pingback', 'trackback')",
                    $this->database->table('comments'),
                    $this->database->table('posts'),
                ),
                [$this->postType],
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
