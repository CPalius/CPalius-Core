<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Wordpress;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\Destination\NodeDestination;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\ForeignDatabase;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\WordpressMediaIndex;
use Modules\Importer\Source\Wordpress\WordpressOrigin;

/**
 * WordPress posts become nodes, with their author and terms resolved through
 * the migrations that ran before.
 *
 * The status map is where most importers quietly lose content. WordPress has
 * publish, draft, pending, private, future and inherit; CPalius has draft,
 * published and scheduled. Anything not certainly public lands as a DRAFT
 * rather than being dropped or published.
 */
final class WordpressPostMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.posts';

    /** @var array<string, string> */
    private readonly array $options;

    private ?WordpressMediaIndex $media = null;

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
        return 'WordPress posts';
    }

    public function dependsOn(): array
    {
        return [
            WordpressAuthorMigration::ID,
            WordpressCategoryMigration::ID,
            WordpressTagMigration::ID,
            WordpressAttachmentMigration::ID,
        ];
    }

    public function options(): array
    {
        return [
            ...WordpressOrigin::commonOptions(),
            MigrationOption::optional('locale', 'Locale the imported content belongs to', 'en'),
            MigrationOption::optional('type', 'CPalius node type to create', 'post'),
            MigrationOption::optional('postType', 'WordPress post type to read (post, page, ...)', 'post'),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static(
            $this->entityManager,
            $this->slugGenerator,
            $this->lookup,
            MigrationOptionResolver::resolve($this->options(), $values),
            $this->suppliedDatabase,
        );
    }

    public function source(): MigrationSourceInterface
    {
        return $this->origin()->posts($this->options['postType'] ?? 'post');
    }

    public function destination(): MigrationDestinationInterface
    {
        return new NodeDestination($this->entityManager, $this->slugGenerator, $this->options['type'] ?? 'post', $this->options['locale'] ?? 'en');
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $title = trim($row->getString('title'));

        if ($title === '') {
            $title = sprintf('WordPress post %s', $row->sourceId);
        }

        $authorLogin = trim($row->getString('creator'));
        $media = $this->media();

        return $row->withData([
            'title' => $title,
            'slug' => trim($row->getString('slug')),
            'status' => $this->status($row),
            'locale' => $this->options['locale'] ?? 'en',
            'publishedAt' => trim($row->getString('publishedAt')),
            'body' => $media->rewrite($row->getString('content')),
            'excerpt' => $media->rewrite($row->getString('excerpt')),
            'featuredAssetId' => $this->featuredAssetId($row, $media),
            'categoryIds' => $this->lookup->findAll(WordpressCategoryMigration::ID, $this->slugs($row->get('categorySlugs'))),
            'tagIds' => $this->lookup->findAll(WordpressTagMigration::ID, $this->slugs($row->get('tagSlugs'))),
            'importedFrom' => 'wordpress',
            'wordpressId' => $row->sourceId,
            'wordpressLink' => trim($row->getString('link')),
            'wordpressGuid' => trim($row->getString('guid')),
            'wordpressAuthor' => $authorLogin,
            'wordpressAuthorUserId' => $authorLogin === '' ? '' : ($this->lookup->find(WordpressAuthorMigration::ID, $authorLogin) ?? ''),
            'wordpressSticky' => trim($row->getString('isSticky')) === '1',
        ]);
    }

    private function featuredAssetId(MigrationRow $row, WordpressMediaIndex $media): string
    {
        $meta = $row->get('meta');
        $thumbnailId = \is_array($meta) ? (string) ($meta['_thumbnail_id'] ?? '') : '';

        if (trim($thumbnailId) === '') {
            return '';
        }

        return (string) ($media->assetIdForAttachment($thumbnailId) ?? '');
    }

    private function media(): WordpressMediaIndex
    {
        return $this->media ??= new WordpressMediaIndex(
            $this->origin()->attachments(),
            $this->lookup,
            $this->entityManager,
            WordpressAttachmentMigration::ID,
        );
    }

    private function status(MigrationRow $row): string
    {
        return trim($row->getString('status')) === 'publish' ? Node::STATUS_PUBLISHED : Node::STATUS_DRAFT;
    }

    /**
     * @return list<string>
     */
    private function slugs(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $slugs = [];

        foreach ($raw as $slug) {
            if (\is_string($slug) && trim($slug) !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    private function origin(): WordpressOrigin
    {
        return new WordpressOrigin($this->entityManager, $this->options, $this->suppliedDatabase);
    }
}
