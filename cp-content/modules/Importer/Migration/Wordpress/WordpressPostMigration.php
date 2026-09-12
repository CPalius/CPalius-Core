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
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Importer\Source\Wordpress\WordpressMediaIndex;
use Modules\Importer\Source\Wordpress\WxrPostSource;
use Modules\Importer\Source\Wordpress\WxrReader;

/**
 * WordPress posts become nodes, with their author and terms resolved through
 * the migrations that ran before.
 *
 * The status map is where most importers quietly lose content. WordPress has
 * publish, draft, pending, private, future and inherit; CPalius has draft,
 * published and scheduled. Anything not certainly public lands as a DRAFT
 * rather than being dropped or published: a private post that appears on the
 * new public site is a disclosure, and a post silently discarded is data loss.
 * Draft is the only option that is neither.
 */
final class WordpressPostMigration implements ConfigurableMigrationInterface
{
    public const ID = 'wordpress.posts';

    private ?WordpressMediaIndex $media = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SlugGenerator $slugGenerator,
        private readonly MigrationLookup $lookup,
        private readonly string $file = '',
        private readonly string $locale = 'en',
        private readonly string $type = 'post',
        private readonly string $postType = 'post',
    ) {
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
        // Authors and terms must be in the map before a post can resolve its
        // own; the registry both orders this and pulls them into a run that
        // only asked for posts.
        return [
            WordpressAuthorMigration::ID,
            WordpressCategoryMigration::ID,
            WordpressTagMigration::ID,
            // Attachments too, so the body can be rewritten to point at assets
            // that exist rather than at a domain about to be switched off.
            WordpressAttachmentMigration::ID,
        ];
    }

    public function options(): array
    {
        return [
            MigrationOption::required('file', 'Path to the WordPress WXR export file'),
            MigrationOption::optional('locale', 'Locale the imported content belongs to', 'en'),
            MigrationOption::optional('type', 'CPalius node type to create', 'post'),
            MigrationOption::optional('postType', 'WordPress post type to read (post, page, ...)', 'post'),
        ];
    }

    public function withOptions(array $values): static
    {
        $resolved = MigrationOptionResolver::resolve($this->options(), $values);

        return new static(
            $this->entityManager,
            $this->slugGenerator,
            $this->lookup,
            $resolved['file'],
            $resolved['locale'],
            $resolved['type'],
            $resolved['postType'],
        );
    }

    public function source(): MigrationSourceInterface
    {
        return new WxrPostSource($this->reader(), $this->postType);
    }

    public function destination(): MigrationDestinationInterface
    {
        return new NodeDestination($this->entityManager, $this->slugGenerator, $this->type, $this->locale);
    }

    public function transform(MigrationRow $row): MigrationRow
    {
        $title = trim($row->getString('title'));

        if ($title === '') {
            // WordPress allows untitled posts; a node cannot have an empty
            // title, and inventing one would put "Untitled" in a menu.
            $title = sprintf('WordPress post %s', $row->sourceId);
        }

        $authorLogin = trim($row->getString('creator'));
        $media = $this->media();

        return $row->withData([
            'title' => $title,
            'slug' => trim($row->getString('slug')),
            'status' => $this->status($row),
            'locale' => $this->locale,
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

    /**
     * The asset behind WordPress's featured image, which it stores as the
     * attachment's post id in _thumbnail_id meta rather than as a URL.
     */
    private function featuredAssetId(MigrationRow $row, WordpressMediaIndex $media): string
    {
        $meta = $row->get('meta');
        $thumbnailId = \is_array($meta) ? (string) ($meta['_thumbnail_id'] ?? '') : '';

        if (trim($thumbnailId) === '') {
            return '';
        }

        return (string) ($media->assetIdForAttachment($thumbnailId) ?? '');
    }

    /**
     * Built once per migration instance: the index costs a streaming pass over
     * the export's attachments, and every row would otherwise pay for it.
     */
    private function media(): WordpressMediaIndex
    {
        return $this->media ??= new WordpressMediaIndex(
            $this->reader(),
            $this->lookup,
            $this->entityManager,
            WordpressAttachmentMigration::ID,
        );
    }

    /**
     * Only "publish" is published. Everything else becomes a draft — see the
     * class docblock for why that is the only safe third option.
     */
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

    private function reader(): WxrReader
    {
        if ($this->file === '') {
            throw new \LogicException('This migration has not been configured; pass -o file=<export.xml>.');
        }

        return new WxrReader($this->file);
    }
}
