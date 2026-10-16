<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Trash\SoftDeletedBin;
use App\Core\Trash\TrashTypeLabels;
use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Recycle bin: soft-deleted content (posts, pages...) is listed, restorable and purgeable; types have names. */
#[CoversNothing]
final class SoftDeletedBinTest extends IntegrationTestCase
{
    public function testSoftDeletedPostIsListedRestoredAndPurged(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $bin = $this->container()->get(SoftDeletedBin::class);

        self::assertArrayHasKey('Node', $bin->classes());
        self::assertArrayNotHasKey('Message', $bin->classes(), 'private messages stay out of the bin');

        $title = 'Bin probe '.bin2hex(random_bytes(3));
        $post = new Node($title, 'bin-probe-'.bin2hex(random_bytes(3)), 'post', 'en');
        $this->em()->persist($post);
        $this->em()->flush();
        $post->softDelete();
        $this->em()->flush();
        $key = 'Node:'.$post->getId();

        $rows = array_column($bin->items('Node', $title), null, 'key');
        self::assertArrayHasKey($key, $rows);
        self::assertSame('post', $rows[$key]['detail']);
        self::assertGreaterThanOrEqual(1, $bin->counts()['Node']);

        self::assertSame($title, $bin->restore($key));
        self::assertNull($post->getDeletedAt());
        self::assertArrayNotHasKey($key, array_column($bin->items('Node', $title), null, 'key'));

        $post->softDelete();
        $this->em()->flush();
        $id = $post->getId();
        $bin->purge($key);
        self::assertNull($this->em()->find(Node::class, $id), 'purge removes the row');
        $copies = (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM cp_trash_items WHERE resource_name = 'Node' AND resource_id = ?", [(string) $id]);
        self::assertSame(0, $copies, 'a purge does not drop a new copy into the bin');
    }

    public function testTypeLabelsAreTranslatedWithAReadableFallback(): void
    {
        $this->container();
        $labels = $this->container()->get(TrashTypeLabels::class);

        self::assertNotSame('BlogComment', $labels->label('BlogComment'));
        self::assertSame('Some New Thing', $labels->label('SomeNewThing'));
        self::assertSame('Some thing', $labels->label('some_thing'));
    }
}
