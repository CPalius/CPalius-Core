<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationRegistry;
use App\Tests\Unit\Core\Migrate\Support\ArraySource;
use App\Tests\Unit\Core\Migrate\Support\RecordingDestination;
use App\Tests\Unit\Core\Migrate\Support\TestMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationRegistry::class)]
final class MigrationRegistryTest extends TestCase
{
    public function testOrdersDependenciesBeforeDependents(): void
    {
        $registry = new MigrationRegistry([
            $this->migration('posts', ['authors']),
            $this->migration('authors'),
            $this->migration('comments', ['posts']),
        ]);

        self::assertSame(
            ['authors', 'posts', 'comments'],
            array_map(static fn (MigrationInterface $m): string => $m->id(), $registry->ordered()),
        );
    }

    public function testAskingForOneMigrationPullsInWhatItDependsOn(): void
    {
        $registry = new MigrationRegistry([
            $this->migration('posts', ['authors']),
            $this->migration('authors'),
            $this->migration('unrelated'),
        ]);

        // Running "posts" without its authors would import content whose author
        // references point at nothing, so the dependency comes along.
        self::assertSame(
            ['authors', 'posts'],
            array_map(static fn (MigrationInterface $m): string => $m->id(), $registry->orderOf(['posts'])),
        );
    }

    public function testACycleIsRefusedWithBothMigrationsNamed(): void
    {
        $registry = new MigrationRegistry([
            $this->migration('a', ['b']),
            $this->migration('b', ['a']),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        $registry->ordered();
    }

    public function testAnUnknownDependencyIsRefusedRatherThanSilentlySkipped(): void
    {
        $registry = new MigrationRegistry([
            $this->migration('posts', ['authors_typo']),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        $registry->ordered();
    }

    public function testTwoMigrationsWithTheSameIdAreRefused(): void
    {
        $registry = new MigrationRegistry([
            $this->migration('posts'),
            $this->migration('posts'),
        ]);

        // They would share an import history in the map table, so the second
        // one would consider the first one's rows already done.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/claim the id/');

        $registry->all();
    }

    public function testGettingAnUnknownMigrationListsTheKnownOnes(): void
    {
        $registry = new MigrationRegistry([$this->migration('posts')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/posts/');

        $registry->get('nope');
    }

    public function testAnEmptyRegistryIsNotAnError(): void
    {
        $registry = new MigrationRegistry([]);

        self::assertSame([], $registry->all());
        self::assertSame([], $registry->ordered());
        self::assertFalse($registry->has('anything'));
    }

    /**
     * @param list<string> $dependsOn
     */
    private function migration(string $id, array $dependsOn = []): TestMigration
    {
        return new TestMigration($id, new ArraySource([]), new RecordingDestination(), $dependsOn);
    }
}
