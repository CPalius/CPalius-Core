<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\PathAlias;

use App\Core\Entity\Event\EntityPostInsertEvent;
use App\Core\Entity\Event\EntityPostUpdateEvent;
use App\Core\Hook\HookContext;
use App\Core\PathAlias\Entity\PathAliasPattern;
use App\Core\PathAlias\PathAliasAutoGenerateListener;
use App\Core\PathAlias\PathAliasGenerator;
use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use App\Core\Token\Provider\NodeTokenProvider;
use App\Core\Token\TokenReplacer;
use App\Core\Token\TokenTypeRegistry;
use App\Entity\Node;
use App\Repository\UrlAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathAliasAutoGenerateListener::class)]
final class PathAliasAutoGenerateListenerTest extends TestCase
{
    public function testNodeSavedWithAMatchingPatternGeneratesAndFlushes(): void
    {
        $node = $this->nodeWithId(42, 'Hello World', 'post');
        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->willReturn(new PathAliasPattern('node', 'post', '/blog/[node:title]'));

        $urlAliases = $this->createMock(UrlAliasRepository::class);
        $urlAliases->method('findOneActiveByPathAndLocale')->willReturn(null);
        $urlAliases->method('pathExists')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $listener = $this->listener($patterns, $urlAliases, $em);
        $listener->onNodeSaved(new HookContext(['event' => new EntityPostInsertEvent($node, 'node')]));
    }

    public function testNodeSavedWithoutAPatternDoesNotFlush(): void
    {
        $node = $this->nodeWithId(42, 'Hello World', 'post');
        $patterns = $this->createMock(PathAliasPatternRepository::class);
        $patterns->method('findOneByTypeAndBundle')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $listener = $this->listener($patterns, $this->createMock(UrlAliasRepository::class), $em);
        $listener->onNodeSaved(new HookContext(['event' => new EntityPostUpdateEvent($node, 'node')]));
    }

    public function testNonNodeEntityIsIgnored(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $listener = $this->listener(
            $this->createMock(PathAliasPatternRepository::class),
            $this->createMock(UrlAliasRepository::class),
            $em,
        );

        // A User event flowing through entity.any.* would never reach this Node-only
        // listener in production (it's only bound to entity.node.*), but the type
        // guard must hold even if it did.
        $listener->onNodeSaved(new HookContext(['event' => new EntityPostInsertEvent(new \App\Entity\User('a@example.com'), 'user')]));
    }

    public function testContextWithoutAnEntityEventDoesNotThrow(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $listener = $this->listener(
            $this->createMock(PathAliasPatternRepository::class),
            $this->createMock(UrlAliasRepository::class),
            $em,
        );

        $listener->onNodeSaved(new HookContext());

        self::assertTrue(true);
    }

    private function listener(
        PathAliasPatternRepository $patterns,
        UrlAliasRepository $urlAliases,
        EntityManagerInterface $entityManager,
    ): PathAliasAutoGenerateListener {
        $catalog = new TokenTypeRegistry();
        $catalog->register('node', 'title', 'token.node.title');

        $generator = new PathAliasGenerator(
            $patterns,
            $urlAliases,
            $entityManager,
            new TokenReplacer([new NodeTokenProvider()], $catalog),
        );

        return new PathAliasAutoGenerateListener($generator, $entityManager);
    }

    private function nodeWithId(int $id, string $title, string $type): Node
    {
        $node = new Node($title, 'ignored-slug', $type, 'en');
        $reflection = new \ReflectionProperty(Node::class, 'id');
        $reflection->setValue($node, $id);

        return $node;
    }
}
