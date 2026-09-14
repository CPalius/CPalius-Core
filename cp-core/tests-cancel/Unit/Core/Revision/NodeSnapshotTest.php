<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Revision;

use App\Core\Revision\NodeSnapshot;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeSnapshot::class)]
final class NodeSnapshotTest extends TestCase
{
    private function snapshot(): NodeSnapshot
    {
        return new NodeSnapshot($this->createMock(EntityManagerInterface::class));
    }

    public function testCapturesEditorialStateOnly(): void
    {
        $node = new Node('Hello', 'hello', 'page', 'en');
        $node->setData(['subtitle' => 'x'])->setStatus('published');

        $snap = $this->snapshot()->capture($node);

        self::assertSame('Hello', $snap['title']);
        self::assertSame('published', $snap['status']);
        self::assertSame(['subtitle' => 'x'], $snap['data']);
        self::assertArrayNotHasKey('locale', $snap);
        self::assertArrayNotHasKey('type', $snap);
    }

    public function testHashIsStableRegardlessOfKeyOrder(): void
    {
        $s = $this->snapshot();
        $a = ['title' => 'T', 'slug' => 's', 'status' => 'draft', 'data' => ['b' => 2, 'a' => 1], 'published_at' => null, 'category_id' => null, 'tag_ids' => []];
        $b = ['data' => ['a' => 1, 'b' => 2], 'status' => 'draft', 'slug' => 's', 'title' => 'T', 'tag_ids' => [], 'category_id' => null, 'published_at' => null];

        self::assertSame($s->hash($a), $s->hash($b));
    }

    public function testApplyWritesBackTitleStatusData(): void
    {
        $node = new Node('Old', 'old', 'page', 'en');
        $this->snapshot()->apply(
            ['title' => 'New', 'slug' => 'new', 'status' => 'draft', 'data' => ['k' => 'v'], 'published_at' => null, 'category_id' => null, 'tag_ids' => []],
            $node,
        );

        self::assertSame('New', $node->getTitle());
        self::assertSame('new', $node->getSlug());
        self::assertSame(['k' => 'v'], $node->getData());
    }
}
