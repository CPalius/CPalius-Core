<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Wordpress;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Migrate\BlogCommentDestination;
use Modules\Importer\Source\Wordpress\WxrCommentSource;
use Modules\Importer\Source\Wordpress\WxrReader;

/**
 * WordPress comments become blog comments on the posts they belonged to.
 *
 * Depends on the posts, obviously — a comment needs something to sit on — and
 * the post id is resolved through the map rather than by matching titles.
 *
 * A comment whose post was not imported is SKIPPED rather than failed. That is
 * the normal case rather than an error: the posts migration deliberately
 * leaves out auto-drafts and pages, and their comments have nowhere to go. A
 * failure per orphaned comment would bury the real problems in noise.
 */
final class WordpressCommentMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.comments';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MigrationLookup $lookup,
        private readonly string $file = '',
        private readonly string $postType = 'post',
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'WordPress comments';
    }

    public function dependsOn(): array
    {
        return [WordpressPostMigration::ID];
    }

    public function options(): array
    {
        return [
            MigrationOption::file('file', 'Path to the WordPress WXR export file'),
            MigrationOption::optional('postType', 'WordPress post type whose comments to read', 'post'),
        ];
    }

    public function withOptions(array $values): static
    {
        $resolved = MigrationOptionResolver::resolve($this->options(), $values);

        return new static($this->entityManager, $this->lookup, $resolved['file'], $resolved['postType']);
    }

    public function source(): MigrationSourceInterface
    {
        return new WxrCommentSource($this->reader(), $this->postType);
    }

    public function destination(): MigrationDestinationInterface
    {
        return new BlogCommentDestination($this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $nodeId = $this->lookup->find(WordpressPostMigration::ID, trim($row->getString('wordpressPostId')));

        if ($nodeId === null) {
            return null;
        }

        $parentCommentId = trim($row->getString('parentCommentId'));

        return $row->withData([
            'nodeId' => $nodeId,
            'body' => $row->getString('body'),
            'status' => $this->status($row),
            'authorName' => trim($row->getString('authorName')),
            'authorEmail' => trim($row->getString('authorEmail')),
            'parentId' => $parentCommentId === '' || $parentCommentId === '0'
                ? ''
                : ($this->lookup->find(self::ID, $parentCommentId) ?? ''),
            'importedFrom' => 'wordpress',
            'wordpressCommentId' => $row->sourceId,
            'wordpressDateGmt' => trim($row->getString('dateGmt')),
        ]);
    }

    /**
     * WordPress writes "1" for approved, "0" for held, and the words "spam" and
     * "trash". Anything else is treated as held for moderation by the
     * destination, which is the safe direction.
     */
    private function status(MigrationRow $row): string
    {
        return match (trim($row->getString('approved'))) {
            '1' => BlogComment::STATUS_APPROVED,
            'spam' => BlogComment::STATUS_SPAM,
            'trash' => BlogComment::STATUS_REJECTED,
            default => BlogComment::STATUS_PENDING,
        };
    }

    private function reader(): WxrReader
    {
        if ($this->file === '') {
            throw new \LogicException('This migration has not been configured; pass -o file=<export.xml>.');
        }

        return new WxrReader($this->file);
    }
}
