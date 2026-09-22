<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Rebuild;

use App\Core\Rebuild\RebuilderInterface;
use App\Core\Rebuild\RebuilderRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RebuilderRegistry::class)]
final class RebuilderRegistryTest extends TestCase
{
    public function testAllIsSortedByPriorityThenId(): void
    {
        $registry = new RebuilderRegistry([
            $this->rebuilder('forum.sections', 30),
            $this->rebuilder('forum.acl', 10),
            $this->rebuilder('forum.topics', 20),
        ]);

        self::assertSame(
            ['forum.acl', 'forum.topics', 'forum.sections'],
            array_keys($registry->all()),
        );
    }

    public function testGetReturnsTheNamedJob(): void
    {
        $topics = $this->rebuilder('forum.topics', 20);
        $registry = new RebuilderRegistry([$topics]);

        self::assertTrue($registry->has('forum.topics'));
        self::assertSame($topics, $registry->get('forum.topics'));
    }

    public function testUnknownIdIsRefused(): void
    {
        $registry = new RebuilderRegistry([$this->rebuilder('forum.topics', 20)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown rebuilder/i');

        $registry->get('forum.missing');
    }

    public function testDuplicateIdsAreRefused(): void
    {
        $registry = new RebuilderRegistry([
            $this->rebuilder('forum.topics', 20),
            $this->rebuilder('forum.topics', 30),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/two rebuilders claim/i');

        $registry->all();
    }

    public function testStudioOmitsPlatformOnlyJobs(): void
    {
        $registry = new RebuilderRegistry([
            $this->rebuilder('core.caches', 90, false),
            $this->rebuilder('forum.topics', 20, true),
        ]);

        self::assertSame(['forum.topics'], array_keys($registry->forStudio()));
        self::assertSame(['forum.topics', 'core.caches'], array_keys($registry->all()));
    }

    private function rebuilder(string $id, int $priority, bool $studioVisible = true): RebuilderInterface
    {
        return new class($id, $priority, $studioVisible) implements RebuilderInterface {
            public function __construct(
                private readonly string $id,
                private readonly int $priority,
                private readonly bool $studioVisible = true,
            ) {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getName(): string
            {
                return 'name.'.$this->id;
            }

            public function getDescription(): string
            {
                return 'desc.'.$this->id;
            }

            public function getBatchSize(): int
            {
                return 500;
            }

            public function getPriority(): int
            {
                return $this->priority;
            }

            public function getTotal(): int
            {
                return 0;
            }

            public function isStudioVisible(): bool
            {
                return $this->studioVisible;
            }

            public function rebuild(int $offset, int $limit): int
            {
                return 0;
            }
        };
    }
}
