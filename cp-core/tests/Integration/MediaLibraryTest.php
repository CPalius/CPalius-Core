<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\TrashManager;
use App\Entity\Asset;
use App\Tests\Support\IntegrationTestCase;
use League\Flysystem\FilesystemOperator;
use Modules\Media\Entity\MediaPlacement;
use Modules\Media\Service\MediaLibraryService;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class MediaLibraryTest extends IntegrationTestCase
{
    private function library(): MediaLibraryService
    {
        /** @var MediaLibraryService $s */
        $s = $this->container()->get(MediaLibraryService::class);

        return $s;
    }

    private function storage(): FilesystemOperator
    {
        /** @var FilesystemOperator $s */
        $s = $this->container()->get('cpalius_storage');

        return $s;
    }

    private function asset(string $name = 'photo.png'): Asset
    {
        $hash = hash('sha256', $name.random_bytes(4));
        $asset = new Asset($hash.'.png', $name, '2026/10', 'image/png', 10, $hash);
        $this->storage()->write($asset->getStorageKey(), 'x');
        $this->em()->persist($asset);
        $this->em()->flush();

        return $asset;
    }

    public function testFoldersNestAndRefuseDuplicatesAndCycles(): void
    {
        $a = $this->library()->createFolder('Photos', null, null);
        $b = $this->library()->createFolder('2026', $a->getId(), null);

        try {
            $this->library()->createFolder('photos', null, null);
            self::fail('duplicate sibling name accepted');
        } catch (\DomainException $e) {
            self::assertSame('media.lib.error.name_exists', $e->getMessage());
        }

        // Same name under another parent is fine.
        $this->library()->createFolder('2026', null, null);

        $this->expectException(\DomainException::class);
        $this->library()->moveFolder((int) $a->getId(), (int) $b->getId());
    }

    public function testAssetsAreBrowsedPerFolderAndRootOnlyShowsUnplaced(): void
    {
        $folder = $this->library()->createFolder('Docs', null, null);
        $inFolder = $this->asset('in.png');
        $atRoot = $this->asset('root.png');
        $this->library()->moveAssets([(int) $inFolder->getId()], (int) $folder->getId());

        $root = $this->library()->browse([], null);
        self::assertSame(['root.png'], array_column($root['assets'], 'name'));
        self::assertSame(['Docs'], array_column($root['folders'], 'name'));

        $inside = $this->library()->browse(['folder' => (int) $folder->getId()], null);
        self::assertSame(['in.png'], array_column($inside['assets'], 'name'));
        self::assertSame('Docs', $inside['breadcrumbs'][0]['name']);

        $found = $this->library()->browse(['q' => 'in'], null);
        self::assertContains('in.png', array_column($found['assets'], 'name'));
        self::assertNotNull($atRoot->getId());
    }

    public function testDeletingAFolderTrashesTheWholeTreeAsOneBatchAndRestoreBringsItBack(): void
    {
        $top = $this->library()->createFolder('Top', null, null);
        $sub = $this->library()->createFolder('Sub', $top->getId(), null);
        $asset = $this->asset('deep.png');
        $this->library()->moveAssets([(int) $asset->getId()], (int) $sub->getId());
        $assetId = (int) $asset->getId();
        $subId = (int) $sub->getId();

        $result = $this->library()->deleteFolder((int) $top->getId());
        self::assertSame(['folders' => 2, 'files' => 1], $result);
        self::assertNull($this->em()->find(Asset::class, $assetId));

        $items = $this->em()->getRepository(TrashItem::class)->findAll();
        self::assertCount(4, $items, 'two folders + asset + placement');
        self::assertCount(1, array_unique(array_map(static fn (TrashItem $t): string => $t->getBatchId(), $items)));
        // The file must still be on disk while the row sits in the bin.
        self::assertTrue($this->storage()->fileExists($asset->getStorageKey()));

        /** @var TrashManager $trash */
        $trash = $this->container()->get(TrashManager::class);
        $restored = $trash->restoreBatch($items[0]->getBatchId());
        self::assertSame(4, $restored['restored']);
        self::assertSame([], $restored['failed']);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Asset::class, $assetId));
        self::assertSame($subId, $this->em()->find(MediaPlacement::class, $assetId)?->getFolderId());
    }

    public function testThePhysicalFileGoesOnlyWhenTheLastRowUsingItIsGone(): void
    {
        $original = $this->asset('orig.png');
        $copy = $this->library()->copyAsset((int) $original->getId());
        $key = $original->getStorageKey();
        self::assertSame($key, $copy->getStorageKey());
        self::assertSame('orig (copy).png', $copy->getOriginalName());

        /** @var TrashManager $trash */
        $trash = $this->container()->get(TrashManager::class);

        $this->library()->deleteAssets([(int) $original->getId()]);
        $trash->emptyBin('Asset');
        self::assertTrue($this->storage()->fileExists($key), 'the copy still uses the file');

        $this->library()->deleteAssets([(int) $copy->getId()]);
        $trash->emptyBin('Asset');
        self::assertFalse($this->storage()->fileExists($key), 'last row gone, file removed');
    }

    public function testAltTextRenameAndFavorites(): void
    {
        $asset = $this->asset('a.png');
        $id = (int) $asset->getId();

        $this->library()->setAlt($id, "  A sunny\nday ");
        $this->library()->renameAsset($id, 'sunny.png');
        $this->library()->setFavorite(7, [$id], true);

        $row = $this->library()->browse(['filter' => 'favorites'], 7)['assets'][0];
        self::assertSame('sunny.png', $row['name']);
        self::assertSame('A sunnyday', $row['alt']);
        self::assertTrue($row['favorite']);
        self::assertSame([], $this->library()->browse(['filter' => 'favorites'], 8)['assets']);

        $this->expectException(\DomainException::class);
        $this->library()->renameAsset($id, '../evil');
    }
}
