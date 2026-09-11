<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\PathAlias;

use App\Core\PathAlias\Entity\PathAliasPattern;
use App\Core\PathAlias\PathAliasGenerator;
use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use App\Core\Token\Provider\NodeTokenProvider;
use App\Core\Token\TokenReplacer;
use App\Core\Token\TokenTypeRegistry;
use App\Entity\Node;
use App\Entity\UrlAlias;
use App\Repository\UrlAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathAliasGenerator::class)]
final class PathAliasGeneratorTest extends TestCase
{
    public function testComputePathSlugifiesEverySegmentAndDropsEmptyOnes(): void
    {
        $node = $this->nodeWithId(42, 'Merhaba Dünya!', 'post', 'tr');
        $reflection = new \ReflectionProperty(Node::class, 'createdAt');
        $reflection->setValue($node, new \DateTimeImmutable('2026-03-05'));

        $generator = $this->generator(
            $this->createMock(PathAliasPatternRepository::class),
            $this->createMock(UrlAliasRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        self::assertSame(
            'blog/2026/merhaba-dunya',
            $generator->computePath('/blog/[node:created:Y]/[node:title]', $node),
        );
    }

    public function testComputePathDropsASegmentThatResolvesEmpty(): void
    {
        $node = $this->nodeWithId(1, 'Title', 'post', 'en');
        $generator = $this->generator(
            $this->createMock(PathAliasPatternRepository::class),
            $this->createMock(UrlAliasRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        // [node:category] resolves to '' (no category assigned) — must not leave a double slash.
        self::assertSame('blog/title', $generator->computePath('/blog/[node:category]/[node:title]', $node));
    }

    public function testGenerateForNodeIsNullWithoutAnEnabledPattern(): void
    {
        $node = $this->nodeWithId(1, 'Title', 'post', 'en');

        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->with('node', 'post')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $generator = $this->generator($patterns, $this->createMock(UrlAliasRepository::class), $em);

        self::assertNull($generator->generateForNode($node));
    }

    public function testGenerateForNodeIsNullWhenPatternDisabled(): void
    {
        $node = $this->nodeWithId(1, 'Title', 'post', 'en');
        $pattern = new PathAliasPattern('node', 'post', '/blog/[node:title]');
        $pattern->setEnabled(false);

        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->willReturn($pattern);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $generator = $this->generator($patterns, $this->createMock(UrlAliasRepository::class), $em);

        self::assertNull($generator->generateForNode($node));
    }

    public function testGenerateForNodeCreatesANewAliasWhenNoneExistsYet(): void
    {
        $node = $this->nodeWithId(42, 'Hello World', 'post', 'en');
        $pattern = new PathAliasPattern('node', 'post', '/blog/[node:title]');

        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->willReturn($pattern);

        $urlAliases = $this->createMock(UrlAliasRepository::class);
        $urlAliases->method('findOneActiveByPathAndLocale')->with('blog/hello-world', 'en')->willReturn(null);
        $urlAliases->method('pathExists')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $em->expects(self::once())->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $generator = $this->generator($patterns, $urlAliases, $em);
        $alias = $generator->generateForNode($node);

        self::assertInstanceOf(UrlAlias::class, $alias);
        self::assertSame($persisted, $alias);
        self::assertSame('blog/hello-world', $alias->getAliasPath());
        self::assertSame('en', $alias->getLocale());
        self::assertSame(UrlAlias::TARGET_NODE, $alias->getTargetType());
        self::assertSame(42, $alias->getTargetNodeId());
    }

    public function testGenerateForNodeIsIdempotentWhenTheCurrentAliasAlreadyMatches(): void
    {
        $node = $this->nodeWithId(42, 'Hello World', 'post', 'en');
        $pattern = new PathAliasPattern('node', 'post', '/blog/[node:title]');

        $existing = new UrlAlias('blog/hello-world', 'en', UrlAlias::TARGET_NODE);
        $existing->setTargetNodeId(42);

        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->willReturn($pattern);

        $urlAliases = $this->createMock(UrlAliasRepository::class);
        $urlAliases->method('findOneActiveByPathAndLocale')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $generator = $this->generator($patterns, $urlAliases, $em);

        self::assertSame($existing, $generator->generateForNode($node));
    }

    public function testGenerateForNodeSuffixesOnCollisionWithAnotherEntity(): void
    {
        $node = $this->nodeWithId(42, 'Hello World', 'post', 'en');
        $pattern = new PathAliasPattern('node', 'post', '/blog/[node:title]');

        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->willReturn($pattern);

        $urlAliases = $this->createMock(UrlAliasRepository::class);
        // Not this node's own alias (findOneActiveByPathAndLocale returns null — belongs to
        // someone else or simply doesn't exist as an ACTIVE row under that exact path).
        $urlAliases->method('findOneActiveByPathAndLocale')->willReturn(null);
        $urlAliases->method('pathExists')->willReturnCallback(
            static fn (string $path): bool => $path === 'blog/hello-world',
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');

        $generator = $this->generator($patterns, $urlAliases, $em);
        $alias = $generator->generateForNode($node);

        self::assertInstanceOf(UrlAlias::class, $alias);
        self::assertSame('blog/hello-world-2', $alias->getAliasPath());
    }

    private function generator(
        PathAliasPatternRepository $patterns,
        UrlAliasRepository $urlAliases,
        EntityManagerInterface $entityManager,
    ): PathAliasGenerator {
        $catalog = new TokenTypeRegistry();
        foreach (['title', 'slug', 'created', 'category'] as $property) {
            $catalog->register('node', $property, 'token.node.'.$property);
        }

        return new PathAliasGenerator(
            $patterns,
            $urlAliases,
            $entityManager,
            new TokenReplacer([new NodeTokenProvider()], $catalog),
        );
    }

    private function nodeWithId(int $id, string $title, string $type, string $locale): Node
    {
        $node = new Node($title, 'ignored-slug', $type, $locale);
        $reflection = new \ReflectionProperty(Node::class, 'id');
        $reflection->setValue($node, $id);

        return $node;
    }
}
