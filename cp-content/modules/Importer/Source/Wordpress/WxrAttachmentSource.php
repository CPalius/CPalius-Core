<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * The attachment items of a WordPress export.
 *
 * Keyed by the attachment's own post id, because that is what a post's
 * _thumbnail_id meta refers to when it names a featured image. Inline images
 * are referenced by URL instead, which is why the attachment URL travels with
 * every row and why WordpressMediaIndex exists to invert it.
 */
final class WxrAttachmentSource implements MigrationSourceInterface
{
    public function __construct(
        private readonly WxrReader $reader,
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress attachments in %s', $this->reader->path());
    }

    public function rows(): iterable
    {
        foreach ($this->reader->items() as $item) {
            if ($item->postType !== 'attachment' || $item->postId === '') {
                continue;
            }

            $url = trim($item->attachmentUrl);

            if ($url === '') {
                continue;
            }

            yield new MigrationRow($item->postId, [
                'attachmentUrl' => $url,
                'title' => $item->title,
                // WordPress keeps alt text in postmeta rather than on the item.
                'alt' => $item->meta['_wp_attachment_image_alt'] ?? '',
                'caption' => $item->excerpt,
                'description' => $item->content,
                'parentId' => $item->parentId,
            ]);
        }
    }

    public function count(): ?int
    {
        return null;
    }
}
