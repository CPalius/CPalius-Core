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
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Migrate\BlogCommentDestination;
use Modules\Importer\Source\Wordpress\WordpressOrigin;

/**
 * WordPress comments become blog comments on the posts they belonged to.
 *
 * A comment whose post was not imported is SKIPPED rather than failed.
 */
final class WordpressCommentMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.comments';

    /** @var array<string, string> */
    private readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        return 'WordPress comments';
    }

    public function dependsOn(): array
    {
        return [WordpressPostMigration::ID];
    }

    public function options(): array
    {
        return [
            ...WordpressOrigin::commonOptions(),
            MigrationOption::optional('postType', 'WordPress post type whose comments to read', 'post'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, $this->lookup, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase);
    }

    public function source(): MigrationSourceInterface
    {
        return $this->origin()->comments($this->options['postType'] ?? 'post');
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

    private function status(MigrationRow $row): string
    {
        return match (trim($row->getString('approved'))) {
            '1' => BlogComment::STATUS_APPROVED,
            'spam' => BlogComment::STATUS_SPAM,
            'trash' => BlogComment::STATUS_REJECTED,
            default => BlogComment::STATUS_PENDING,
        };
    }

    private function origin(): WordpressOrigin
    {
        return new WordpressOrigin($this->entityManager, $this->options, $this->suppliedDatabase);
    }
}
