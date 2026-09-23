<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationSourceInterface;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Translates the old site's media URLs into this one's.
 *
 * This is the part naive importers leave out, and it is why imported sites so
 * often end up with every image pointing back at a domain that is about to be
 * switched off. The content came across; the pictures in it did not.
 *
 * Two WordPress details make it more than a search and replace:
 *
 *  1. Posts almost never reference the original file. WordPress generates
 *     derivatives and writes the sized one into the markup, so the body says
 *     photo-1024x768.jpg while the export's attachment says photo.jpg. Both
 *     have to resolve to the same asset, so the size suffix is stripped before
 *     lookup. "-scaled" — what WordPress appends to very large uploads — is
 *     stripped the same way.
 *  2. The same file appears under several absolute prefixes over a site's life
 *     (http and https, with and without www, a CDN host). Matching on the
 *     uploads-relative tail rather than the whole URL survives all of them.
 *
 * The index is built lazily and once: it costs one streaming pass over the
 * attachments in the export, which is cheap next to reading every post twice.
 */
final class WordpressMediaIndex
{
    /** @var array<string, string>|null uploads-relative path => new public URL */
    private ?array $byPath = null;

    /** @var array<string, int>|null WordPress attachment id => CPalius asset id */
    private ?array $byAttachmentId = null;

    public function __construct(
        private readonly MigrationSourceInterface $attachments,
        private readonly MigrationLookup $lookup,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $migrationId,
    ) {
    }

    /**
     * The asset a post's _thumbnail_id refers to.
     */
    public function assetIdForAttachment(string $wordpressAttachmentId): ?int
    {
        $this->build();

        return $this->byAttachmentId[$wordpressAttachmentId] ?? null;
    }

    /**
     * Rewrites every old media URL in a body of HTML.
     *
     * A URL with no imported asset behind it is left exactly as it was. That is
     * deliberate: a link this cannot resolve still works while the old site is
     * up, whereas a blanked or guessed href is broken immediately and silently.
     */
    public function rewrite(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $this->build();

        if ($this->byPath === []) {
            return $html;
        }

        $rewritten = preg_replace_callback(
            '#(?:https?:)?//[^\s"\'<>()]+|(?<=["\'(\s])/[^\s"\'<>()]+#i',
            fn (array $m): string => $this->replacement($m[0]),
            $html,
        );

        return $rewritten ?? $html;
    }

    /**
     * The new public URL for one old media URL, or null when it is unknown.
     */
    public function urlFor(string $url): ?string
    {
        $this->build();

        $relative = (new UrlPath())->relative($url);

        if ($relative === null) {
            return null;
        }

        return $this->byPath[$this->normalise($relative)] ?? null;
    }

    private function replacement(string $url): string
    {
        return $this->urlFor($url) ?? $url;
    }

    /**
     * Drops the "-WIDTHxHEIGHT" and "-scaled" suffixes WordPress adds to
     * derivatives, so every size of one picture finds the one asset.
     */
    private function normalise(string $relativePath): string
    {
        $normalised = preg_replace('#-\d+x\d+(\.[A-Za-z0-9]+)$#', '$1', $relativePath) ?? $relativePath;

        return preg_replace('#-scaled(\.[A-Za-z0-9]+)$#', '$1', $normalised) ?? $normalised;
    }

    private function build(): void
    {
        if ($this->byPath !== null) {
            return;
        }

        $this->byPath = [];
        $this->byAttachmentId = [];
        $urlPath = new UrlPath();
        /** @var list<array{0: int, 1: string}> $pending */
        $pending = [];
        $assetIds = [];

        foreach ($this->attachments->rows() as $row) {
            $assetId = $this->lookup->findInt($this->migrationId, $row->sourceId);

            if ($assetId === null) {
                continue;
            }

            $this->byAttachmentId[$row->sourceId] = $assetId;

            $relative = $urlPath->relative($row->getString('attachmentUrl'));

            if ($relative === null) {
                continue;
            }

            $pending[] = [$assetId, $this->normalise($relative)];
            $assetIds[$assetId] = true;
        }

        $assets = $this->assetsById(array_keys($assetIds));

        foreach ($pending as [$assetId, $relative]) {
            $asset = $assets[$assetId] ?? null;

            if ($asset === null) {
                continue;
            }

            $this->byPath[$relative] = '/uploads/'.trim($asset->getPath(), '/').'/'.$asset->getFilename();
        }
    }

    /**
     * One IN() for every imported attachment, not find() inside the loop —
     * the first post would otherwise read cp_assets once per picture and trip
     * Law 6.1 the same way the map did.
     *
     * @param list<int> $ids
     *
     * @return array<int, Asset>
     */
    private function assetsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Asset> $assets */
        $assets = $this->entityManager->getRepository(Asset::class)->findBy(['id' => $ids]);
        $found = [];

        foreach ($assets as $asset) {
            $id = $asset->getId();

            if ($id !== null) {
                $found[$id] = $asset;
            }
        }

        return $found;
    }
}
