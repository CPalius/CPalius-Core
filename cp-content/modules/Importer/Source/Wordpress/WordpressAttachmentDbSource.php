<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;

/**
 * wp_posts of type attachment. The file path lives in _wp_attached_file.
 */
final class WordpressAttachmentDbSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly ForeignDatabase $database,
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress attachments in %s', $this->database->table('posts'));
    }

    public function rows(): iterable
    {
        $posts = $this->database->table('posts');
        $meta = $this->database->table('postmeta');
        $hasMeta = $this->database->hasTable('postmeta');

        $from = $hasMeta
            ? sprintf(
                '%s p
                 LEFT JOIN %s f ON f.post_id = p.ID AND f.meta_key = \'_wp_attached_file\'
                 LEFT JOIN %s a ON a.post_id = p.ID AND a.meta_key = \'_wp_attachment_image_alt\'',
                $posts,
                $meta,
                $meta,
            )
            : $posts.' p';

        $select = $hasMeta
            ? 'p.ID, p.post_title, p.post_excerpt, p.post_content, p.guid, p.post_parent, f.meta_value AS attached_file, a.meta_value AS alt'
            : 'p.ID, p.post_title, p.post_excerpt, p.post_content, p.guid, p.post_parent';

        $inner = new DatabaseSource(
            $this->database,
            $from,
            'p.ID',
            $select,
            "p.post_type = 'attachment'",
            200,
            $this->describe(),
        );

        foreach ($inner->rows() as $row) {
            $id = $row->getString('ID');
            $url = $this->url($row->getString('attached_file'), $row->getString('guid'));

            if ($id === '' || $url === '') {
                continue;
            }

            yield new MigrationRow($id, [
                'attachmentUrl' => $url,
                'title' => $row->getString('post_title'),
                'alt' => $row->getString('alt'),
                'caption' => $row->getString('post_excerpt'),
                'description' => $row->getString('post_content'),
                'parentId' => $row->getString('post_parent'),
            ]);
        }
    }

    public function count(): ?int
    {
        try {
            return (int) $this->database->connection()->fetchOne(
                sprintf("SELECT COUNT(*) FROM %s WHERE post_type = 'attachment'", $this->database->table('posts')),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function url(string $attachedFile, string $guid): string
    {
        $attachedFile = str_replace('\\', '/', trim($attachedFile));

        if ($attachedFile !== '') {
            if (str_contains($attachedFile, 'wp-content/uploads/')) {
                return $attachedFile;
            }

            return 'wp-content/uploads/'.ltrim($attachedFile, '/');
        }

        return trim($guid);
    }
}
