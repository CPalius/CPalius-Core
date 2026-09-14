<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Config\Provider;

use App\Core\Config\Provider\PathAliasPatternConfigProvider;
use App\Core\PathAlias\Entity\PathAliasPattern;
use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathAliasPatternConfigProvider::class)]
final class PathAliasPatternConfigProviderTest extends TestCase
{
    public function testDocumentsListsOnePerBundle(): void
    {
        $repository = $this->createMock(PathAliasPatternRepository::class);
        $repository->method('distinctBundles')->with('node')->willReturn(['post', 'page']);

        $provider = new PathAliasPatternConfigProvider($repository, $this->createMock(EntityManagerInterface::class));

        self::assertSame(['path_pattern.post', 'path_pattern.page'], $provider->documents());
    }

    public function testOwnsDocument(): void
    {
        $provider = new PathAliasPatternConfigProvider(
            $this->createMock(PathAliasPatternRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        self::assertTrue($provider->ownsDocument('path_pattern.post'));
        self::assertFalse($provider->ownsDocument('field.post'));
    }

    public function testExportDocument(): void
    {
        $pattern = new PathAliasPattern('node', 'post', '/blog/[node:title]');

        $repository = $this->createMock(PathAliasPatternRepository::class);
        $repository->method('findOneByTypeAndBundle')->with('node', 'post')->willReturn($pattern);

        $provider = new PathAliasPatternConfigProvider($repository, $this->createMock(EntityManagerInterface::class));

        self::assertSame(['pattern' => '/blog/[node:title]', 'enabled' => true], $provider->exportDocument('path_pattern.post'));
    }

    public function testImportDocumentCreatesWhenMissing(): void
    {
        $repository = $this->createMock(PathAliasPatternRepository::class);
        $repository->method('findOneByTypeAndBundle')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(PathAliasPattern::class));

        $provider = new PathAliasPatternConfigProvider($repository, $em);
        $applied = $provider->importDocument('path_pattern.post', ['pattern' => '/blog/[node:title]', 'enabled' => true]);

        self::assertSame(['created post'], $applied);
    }

    public function testImportDocumentRemovesWhenPatternIsEmptyAndARowExists(): void
    {
        $existing = new PathAliasPattern('node', 'post', '/blog/[node:title]');

        $repository = $this->createMock(PathAliasPatternRepository::class);
        $repository->method('findOneByTypeAndBundle')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($existing);

        $provider = new PathAliasPatternConfigProvider($repository, $em);
        $applied = $provider->importDocument('path_pattern.post', ['pattern' => '']);

        self::assertSame(['removed post'], $applied);
    }
}
