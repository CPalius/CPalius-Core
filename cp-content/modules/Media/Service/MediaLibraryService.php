<?php

declare(strict_types=1);

namespace Modules\Media\Service;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Media\AssetUrlGenerator;
use App\Core\Media\ImageProcessor;
use App\Entity\Asset;
use App\Entity\NodeFieldIndex;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Media\Entity\MediaFolder;
use Modules\Media\Entity\MediaPlacement;
use Modules\Media\Entity\MediaUserFlag;

/**
 * Folders, placement, favourites and rename/alt/copy on top of the core Asset.
 *
 * Every failure a user can cause throws \DomainException carrying a translation
 * key (media.lib.error.*), which the controller turns into a localized 400.
 * Deleting goes through the ORM so the recycle bin captures the rows; the file
 * itself is removed later by AssetTrashPurgeHandler.
 */
final class MediaLibraryService
{
    public const PER_PAGE = 60;
    private const MAX_DEPTH = 50;

    /** @var array<string, array{0: string, 1: string}> sort key => [DQL field, direction] */
    private const SORTS = [
        'name_asc' => ['a.originalName', 'ASC'],
        'name_desc' => ['a.originalName', 'DESC'],
        'date_asc' => ['a.createdAt', 'ASC'],
        'date_desc' => ['a.createdAt', 'DESC'],
        'size_asc' => ['a.fileSize', 'ASC'],
        'size_desc' => ['a.fileSize', 'DESC'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetUrlGenerator $urls,
        private readonly ImageProcessor $images,
    ) {
    }

    /**
     * @param array{folder?: ?int, q?: string, type?: string, filter?: string, sort?: string, page?: int} $opts
     *
     * @return array{folder: ?array{id: int, name: string}, breadcrumbs: list<array{id: int, name: string}>, folders: list<array<string, mixed>>, assets: list<array<string, mixed>>, hasMore: bool}
     */
    public function browse(array $opts, ?int $userId): array
    {
        $folderId = $opts['folder'] ?? null;
        $q = trim((string) ($opts['q'] ?? ''));
        $filter = (string) ($opts['filter'] ?? 'all');
        $page = max(1, (int) ($opts['page'] ?? 1));

        $folder = $folderId !== null ? $this->em->find(MediaFolder::class, $folderId) : null;
        if ($folderId !== null && !$folder instanceof MediaFolder) {
            throw new \DomainException('media.lib.error.folder_not_found');
        }

        $assets = $this->assetsQuery($opts, $folderId, $q, $filter, $userId, $page);
        $hasMore = \count($assets) > self::PER_PAGE;
        $assets = \array_slice($assets, 0, self::PER_PAGE);

        $folders = [];
        if ($filter === 'all' && $page === 1) {
            $folders = $this->foldersFor($folderId, $q);
        }

        return [
            'folder' => $folder instanceof MediaFolder ? ['id' => (int) $folder->getId(), 'name' => $folder->getName()] : null,
            'breadcrumbs' => $folder instanceof MediaFolder ? $this->breadcrumbs($folder) : [],
            'folders' => $folders,
            'assets' => $this->presentAssets($assets, $userId),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Every folder, for the sidebar tree and the "move to" dialog.
     *
     * @return list<array{id: int, parentId: ?int, name: string, color: ?string}>
     */
    public function tree(): array
    {
        $out = [];
        foreach ($this->em->getRepository(MediaFolder::class)->findBy([], ['name' => 'ASC']) as $f) {
            $out[] = ['id' => (int) $f->getId(), 'parentId' => $f->getParentId(), 'name' => $f->getName(), 'color' => $f->getColor()];
        }

        return $out;
    }

    public function createFolder(string $name, ?int $parentId, ?int $userId, ?string $color = null): MediaFolder
    {
        $name = $this->cleanName($name);
        $this->assertParent($parentId);
        $this->assertNameFree($name, $parentId, null);

        $folder = new MediaFolder($name, $parentId, $userId);
        $folder->setColor($this->cleanColor($color));
        $this->em->persist($folder);
        $this->em->flush();

        return $folder;
    }

    public function renameFolder(int $id, string $name): void
    {
        $folder = $this->folder($id);
        $name = $this->cleanName($name);
        $this->assertNameFree($name, $folder->getParentId(), $id);

        $folder->setName($name);
        $this->em->flush();
    }

    public function moveFolder(int $id, ?int $parentId): void
    {
        $folder = $this->folder($id);
        $this->assertParent($parentId);

        if ($parentId !== null && ($parentId === $id || \in_array($parentId, $this->descendantIds($id), true))) {
            throw new \DomainException('media.lib.error.move_into_self');
        }

        $this->assertNameFree($folder->getName(), $parentId, $id);
        $folder->setParentId($parentId);
        $this->em->flush();
    }

    /**
     * Removes the folder, its sub-folders and every file inside them in ONE
     * flush, so the recycle bin records them as one batch and "restore with
     * related" brings the whole tree back.
     *
     * @return array{folders: int, files: int}
     */
    public function deleteFolder(int $id): array
    {
        $folder = $this->folder($id);
        $folderIds = [$id, ...$this->descendantIds($id)];

        $placements = $this->em->getRepository(MediaPlacement::class)->findBy(['folderId' => $folderIds]);
        $assetIds = array_map(static fn (MediaPlacement $p): int => $p->getAssetId(), $placements);

        foreach ($placements as $placement) {
            $this->em->remove($placement);
        }
        foreach ($this->em->getRepository(Asset::class)->findBy(['id' => $assetIds]) as $asset) {
            $this->em->remove($asset);
        }
        foreach ($this->em->getRepository(MediaFolder::class)->findBy(['id' => $folderIds]) as $f) {
            $this->em->remove($f);
        }
        $this->em->flush();
        $this->forgetFlags($assetIds);

        return ['folders' => \count($folderIds), 'files' => \count($assetIds)];
    }

    /**
     * @param list<int> $assetIds
     */
    public function moveAssets(array $assetIds, ?int $folderId): int
    {
        $this->assertParent($folderId);
        $moved = 0;

        foreach ($this->em->getRepository(Asset::class)->findBy(['id' => $assetIds]) as $asset) {
            $placement = $this->em->find(MediaPlacement::class, $asset->getId());

            if ($folderId === null) {
                if ($placement instanceof MediaPlacement) {
                    $this->em->remove($placement);
                }
            } elseif ($placement instanceof MediaPlacement) {
                $placement->setFolderId($folderId);
            } else {
                $this->em->persist(new MediaPlacement((int) $asset->getId(), $folderId));
            }
            ++$moved;
        }

        $this->em->flush();

        return $moved;
    }

    public function renameAsset(int $id, string $name): void
    {
        $asset = $this->asset($id);
        $name = $this->cleanName($name, 255);
        $asset->setOriginalName($name);
        $this->em->flush();
    }

    public function setAlt(int $id, string $alt): void
    {
        $asset = $this->asset($id);
        $alt = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $alt) ?? '');
        $alt = mb_substr($alt, 0, 255);

        if ($alt === '') {
            $metadata = $asset->getMetadata();
            unset($metadata['alt']);
            $asset->setMetadata($metadata);
        } else {
            $asset->setMetadataValue('alt', $alt);
        }

        $this->em->flush();
    }

    /**
     * A second row over the same stored file. Storage is content-addressed, so
     * there is nothing to duplicate on disk — the copy exists to carry its own
     * name, alt text and folder, and AssetTrashPurgeHandler keeps the file
     * until the last row using it is gone.
     */
    public function copyAsset(int $id): Asset
    {
        $source = $this->asset($id);

        $copy = new Asset(
            $source->getFilename(),
            $this->copyName($source->getOriginalName()),
            $source->getPath(),
            $source->getMimeType(),
            $source->getFileSize(),
            $source->getHash(),
        );
        $copy->setMetadata($source->getMetadata());
        $this->em->persist($copy);
        $this->em->flush();

        $placement = $this->em->find(MediaPlacement::class, $source->getId());
        if ($placement instanceof MediaPlacement) {
            $this->em->persist(new MediaPlacement((int) $copy->getId(), $placement->getFolderId()));
            $this->em->flush();
        }

        return $copy;
    }

    /**
     * Puts an upload into a folder. When the upload was a duplicate of a file
     * that already exists (content-addressed dedup returned the old row) and
     * that row lives elsewhere, a new row over the same file is created in the
     * target folder instead of pulling the original out of its folder.
     */
    public function placeUpload(Asset $asset, bool $wasExisting, int $folderId, string $clientName): Asset
    {
        $this->assertParent($folderId);
        $placement = $this->em->find(MediaPlacement::class, $asset->getId());

        if ($wasExisting && ($placement instanceof MediaPlacement ? $placement->getFolderId() !== $folderId : true)) {
            $copy = $this->copyAsset((int) $asset->getId());
            $name = trim(basename(str_replace('\\', '/', $clientName)));
            if ($name !== '') {
                $copy->setOriginalName($name);
            }
            $this->em->flush();
            $this->moveAssets([(int) $copy->getId()], $folderId);

            return $copy;
        }

        $this->moveAssets([(int) $asset->getId()], $folderId);

        return $asset;
    }

    /**
     * @param list<int> $assetIds
     */
    public function deleteAssets(array $assetIds): int
    {
        $assets = $this->em->getRepository(Asset::class)->findBy(['id' => $assetIds]);

        foreach ($this->em->getRepository(MediaPlacement::class)->findBy(['assetId' => $assetIds]) as $placement) {
            $this->em->remove($placement);
        }
        foreach ($assets as $asset) {
            $this->em->remove($asset);
        }
        $this->em->flush();
        $this->forgetFlags($assetIds);

        return \count($assets);
    }

    /**
     * @param list<int> $assetIds
     */
    public function setFavorite(int $userId, array $assetIds, bool $favorite): void
    {
        foreach ($this->em->getRepository(Asset::class)->findBy(['id' => $assetIds]) as $asset) {
            $this->flag($userId, (int) $asset->getId())->setFavorite($favorite);
        }
        $this->em->flush();
    }

    public function markOpened(int $userId, int $assetId): void
    {
        $this->asset($assetId);
        $this->flag($userId, $assetId)->touch();
        $this->em->flush();
    }

    /**
     * Where an asset is referenced. Covers image/file fields on content and
     * member avatars — the two places a media id is stored as a plain id. Text
     * that merely links to /uploads/... is not tracked.
     *
     * @return list<array{kind: string, id: int, label: string, field?: string}>
     */
    public function usage(int $assetId): array
    {
        $this->asset($assetId);
        $out = [];

        $fieldNames = array_map(
            static fn (FieldDefinition $d): string => $d->getName(),
            $this->em->getRepository(FieldDefinition::class)->findBy(['type' => ['image', 'file']]),
        );

        if ($fieldNames !== []) {
            $rows = $this->em->createQueryBuilder()
                ->select('n.id AS id, n.title AS title, i.fieldName AS field')
                ->from(NodeFieldIndex::class, 'i')
                ->join('i.node', 'n')
                ->where('i.valueInt = :id')
                ->andWhere('i.fieldName IN (:names)')
                ->setParameter('id', $assetId)
                ->setParameter('names', array_values(array_unique($fieldNames)))
                ->setMaxResults(100)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $out[] = ['kind' => 'content', 'id' => (int) $row['id'], 'label' => (string) $row['title'], 'field' => (string) $row['field']];
            }
        }

        $table = $this->em->getClassMetadata(User::class)->getTableName();
        $userIds = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM '.$this->em->getConnection()->quoteIdentifier($table)." WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.avatar_asset_id')) AS UNSIGNED) = ? LIMIT 100",
            [$assetId],
        );
        foreach ($this->em->getRepository(User::class)->findBy(['id' => $userIds]) as $user) {
            $out[] = ['kind' => 'avatar', 'id' => (int) $user->getId(), 'label' => $user->getPublicDisplayName()];
        }

        return $out;
    }

    public function asset(int $id): Asset
    {
        $asset = $this->em->find(Asset::class, $id);
        if (!$asset instanceof Asset) {
            throw new \DomainException('media.lib.error.asset_not_found');
        }

        return $asset;
    }

    /**
     * @param list<Asset> $assets
     *
     * @return list<array<string, mixed>>
     */
    public function presentAssets(array $assets, ?int $userId): array
    {
        $ids = array_map(static fn (Asset $a): int => (int) $a->getId(), $assets);
        $placements = $ids === [] ? [] : $this->em->getRepository(MediaPlacement::class)->findBy(['assetId' => $ids]);
        $folderOf = [];
        foreach ($placements as $p) {
            $folderOf[$p->getAssetId()] = $p->getFolderId();
        }

        $favorites = [];
        if ($userId !== null && $ids !== []) {
            $favorites = $this->favoriteIds($userId, $ids);
        }

        return array_map(fn (Asset $a): array => $this->presentAsset($a, $folderOf[$a->getId()] ?? null, isset($favorites[$a->getId()])), $assets);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAsset(Asset $a, ?int $folderId, bool $favorite): array
    {
        $key = $a->getStorageKey();
        $isImage = str_starts_with($a->getMimeType(), 'image/');
        $mime = strtolower($a->getMimeType());
        $url = $this->urls->forKey($key);
        $thumb = null;

        if ($isImage) {
            $thumb = str_contains($mime, 'svg') || str_contains($mime, 'gif')
                ? $url
                : $this->urls->rewrite($this->images->thumbnail($key, 320, 320));
        }

        return [
            'id' => (int) $a->getId(),
            'name' => $a->getOriginalName(),
            'url' => $url,
            'thumb' => $thumb,
            'mime' => $a->getMimeType(),
            'kind' => $isImage ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document')),
            'size' => $a->getFileSize(),
            'alt' => (string) $a->getMetadataValue('alt', ''),
            'width' => $a->getMetadataValue('width'),
            'height' => $a->getMetadataValue('height'),
            'createdAt' => $a->getCreatedAt()->format(\DATE_ATOM),
            'folderId' => $folderId,
            'favorite' => $favorite,
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    private function favoriteIds(int $userId, array $ids): array
    {
        $out = [];
        foreach ($this->em->getRepository(MediaUserFlag::class)->findBy(['userId' => $userId, 'assetId' => $ids]) as $flag) {
            if ($flag->isFavorite()) {
                $out[$flag->getAssetId()] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return list<Asset>
     */
    private function assetsQuery(array $opts, ?int $folderId, string $q, string $filter, ?int $userId, int $page): array
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Asset::class, 'a');

        if ($filter === 'favorites' || $filter === 'recent') {
            if ($userId === null) {
                return [];
            }
            $qb->innerJoin(MediaUserFlag::class, 'f', 'WITH', 'f.assetId = a.id AND f.userId = :u')->setParameter('u', $userId);
            if ($filter === 'favorites') {
                $qb->andWhere('f.favorite = true');
            } else {
                $qb->andWhere('f.lastOpenedAt IS NOT NULL')->orderBy('f.lastOpenedAt', 'DESC');
            }
        } elseif ($q === '') {
            $qb->leftJoin(MediaPlacement::class, 'p', 'WITH', 'p.assetId = a.id');
            if ($folderId === null) {
                $qb->andWhere('p.folderId IS NULL');
            } else {
                $qb->andWhere('p.folderId = :folder')->setParameter('folder', $folderId);
            }
        }

        if ($q !== '') {
            $qb->andWhere('a.originalName LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
        }

        match ((string) ($opts['type'] ?? '')) {
            'image' => $qb->andWhere('a.mimeType LIKE :t')->setParameter('t', 'image/%'),
            'video' => $qb->andWhere('a.mimeType LIKE :t')->setParameter('t', 'video/%'),
            'document' => $qb->andWhere('a.mimeType NOT LIKE :ti AND a.mimeType NOT LIKE :tv')->setParameter('ti', 'image/%')->setParameter('tv', 'video/%'),
            default => null,
        };

        if ($filter !== 'recent') {
            [$field, $dir] = self::SORTS[(string) ($opts['sort'] ?? '')] ?? self::SORTS['date_desc'];
            $qb->orderBy($field, $dir)->addOrderBy('a.id', 'DESC');
        }

        return $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1)->getQuery()->getResult();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foldersFor(?int $parentId, string $q): array
    {
        $qb = $this->em->createQueryBuilder()->select('f')->from(MediaFolder::class, 'f')->orderBy('f.name', 'ASC');

        if ($q !== '') {
            $qb->andWhere('f.name LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
        } elseif ($parentId === null) {
            $qb->andWhere('f.parentId IS NULL');
        } else {
            $qb->andWhere('f.parentId = :p')->setParameter('p', $parentId);
        }

        /** @var list<MediaFolder> $folders */
        $folders = $qb->getQuery()->getResult();
        if ($folders === []) {
            return [];
        }

        $ids = array_map(static fn (MediaFolder $f): int => (int) $f->getId(), $folders);
        $fileCounts = [];
        foreach ($this->em->createQueryBuilder()->select('p.folderId AS fid, COUNT(p.assetId) AS c')->from(MediaPlacement::class, 'p')->where('p.folderId IN (:ids)')->setParameter('ids', $ids)->groupBy('p.folderId')->getQuery()->getArrayResult() as $row) {
            $fileCounts[(int) $row['fid']] = (int) $row['c'];
        }

        return array_map(static fn (MediaFolder $f): array => [
            'id' => (int) $f->getId(),
            'parentId' => $f->getParentId(),
            'name' => $f->getName(),
            'color' => $f->getColor(),
            'files' => $fileCounts[(int) $f->getId()] ?? 0,
        ], $folders);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function breadcrumbs(MediaFolder $folder): array
    {
        $trail = [];
        $current = $folder;

        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            array_unshift($trail, ['id' => (int) $current->getId(), 'name' => $current->getName()]);
            $parent = $current->getParentId() !== null ? $this->em->find(MediaFolder::class, $current->getParentId()) : null;
            if (!$parent instanceof MediaFolder) {
                break;
            }
            $current = $parent;
        }

        return $trail;
    }

    /**
     * @return list<int>
     */
    private function descendantIds(int $id): array
    {
        $all = [];
        $frontier = [$id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; ++$depth) {
            $children = $this->em->createQueryBuilder()
                ->select('f.id')->from(MediaFolder::class, 'f')
                ->where('f.parentId IN (:p)')->setParameter('p', $frontier)
                ->getQuery()->getSingleColumnResult();
            $frontier = array_values(array_diff(array_map('intval', $children), $all, [$id]));
            array_push($all, ...$frontier);
        }

        return $all;
    }

    private function folder(int $id): MediaFolder
    {
        $folder = $this->em->find(MediaFolder::class, $id);
        if (!$folder instanceof MediaFolder) {
            throw new \DomainException('media.lib.error.folder_not_found');
        }

        return $folder;
    }

    private function assertParent(?int $parentId): void
    {
        if ($parentId !== null) {
            $this->folder($parentId);
        }
    }

    private function assertNameFree(string $name, ?int $parentId, ?int $exceptId): void
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(f.id)')->from(MediaFolder::class, 'f')
            ->where('LOWER(f.name) = :n')->setParameter('n', mb_strtolower($name));

        if ($parentId === null) {
            $qb->andWhere('f.parentId IS NULL');
        } else {
            $qb->andWhere('f.parentId = :p')->setParameter('p', $parentId);
        }
        if ($exceptId !== null) {
            $qb->andWhere('f.id <> :x')->setParameter('x', $exceptId);
        }

        if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
            throw new \DomainException('media.lib.error.name_exists');
        }
    }

    private function cleanName(string $name, int $max = 150): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || mb_strlen($name) > $max || preg_match('/[\/\\\\<>:"|?*\x00-\x1F\x7F]/u', $name) === 1 || $name === '.' || $name === '..') {
            throw new \DomainException('media.lib.error.name_invalid');
        }

        return $name;
    }

    private function cleanColor(?string $color): ?string
    {
        return $color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : null;
    }

    private function copyName(string $name): string
    {
        $ext = pathinfo($name, \PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($name, 0, -(\strlen($ext) + 1)) : $name;

        return mb_substr($base.' (copy)'.($ext !== '' ? '.'.$ext : ''), 0, 255);
    }

    private function flag(int $userId, int $assetId): MediaUserFlag
    {
        $flag = $this->em->find(MediaUserFlag::class, ['userId' => $userId, 'assetId' => $assetId]);
        if (!$flag instanceof MediaUserFlag) {
            $flag = new MediaUserFlag($userId, $assetId);
            $this->em->persist($flag);
        }

        return $flag;
    }

    /**
     * @param list<int> $assetIds
     */
    private function forgetFlags(array $assetIds): void
    {
        if ($assetIds === []) {
            return;
        }

        $this->em->createQueryBuilder()->delete(MediaUserFlag::class, 'f')
            ->where('f.assetId IN (:ids)')->setParameter('ids', $assetIds)
            ->getQuery()->execute();
    }
}
