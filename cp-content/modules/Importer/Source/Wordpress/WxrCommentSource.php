<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * The comments buried inside a WordPress export's items.
 *
 * WXR does not have a comments section; every comment lives inside the post it
 * belongs to. Flattening them here is what lets comments be their own
 * migration with its own map — so a comment import can be re-run, or rolled
 * back, without touching the posts.
 *
 * Pingbacks and trackbacks are dropped. They are records of other sites
 * linking in, most of them long dead or spam, and they are not comments anyone
 * wants to read on the new site.
 */
final class WxrCommentSource implements MigrationSourceInterface
{
    private const NOT_COMMENTS = ['pingback', 'trackback'];

    public function __construct(
        private readonly WxrReader $reader,
        private readonly string $postType = 'post',
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress comments on "%s" items in %s', $this->postType, $this->reader->path());
    }

    public function rows(): iterable
    {
        foreach ($this->reader->items() as $item) {
            if ($item->postType !== $this->postType || $item->postId === '') {
                continue;
            }

            foreach ($item->comments as $comment) {
                $id = trim($comment['comment_id'] ?? '');

                if ($id === '' || \in_array(trim($comment['comment_type'] ?? ''), self::NOT_COMMENTS, true)) {
                    continue;
                }

                yield new MigrationRow($id, [
                    'wordpressPostId' => $item->postId,
                    'authorName' => $comment['comment_author'] ?? '',
                    'authorEmail' => $comment['comment_author_email'] ?? '',
                    'authorUrl' => $comment['comment_author_url'] ?? '',
                    'body' => $comment['comment_content'] ?? '',
                    'approved' => $comment['comment_approved'] ?? '',
                    'parentCommentId' => trim($comment['comment_parent'] ?? ''),
                    'dateGmt' => $comment['comment_date_gmt'] ?? '',
                ]);
            }
        }
    }

    public function count(): ?int
    {
        return null;
    }
}
