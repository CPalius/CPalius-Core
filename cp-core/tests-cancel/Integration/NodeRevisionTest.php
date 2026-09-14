<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Revision\Entity\NodeRevision;
use App\Core\Revision\RevisionManager;
use App\Entity\Node;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Revision engine: capture, list, restore, prune.
 *
 * The property that keeps the table from growing without bound is that an
 * unchanged node produces no revision — capture() compares the snapshot hash
 * rather than writing on every save. The property that makes restore useful is
 * that restoring is itself a change, so the state you restored from is still
 * reachable afterwards.
 */
#[CoversClass(RevisionManager::class)]
#[CoversClass(NodeRevision::class)]
final class NodeRevisionTest extends IntegrationTestCase
{
    public function testLifecycle(): void
    {
        $container = $this->container();
        $em = $this->em();

        $author = new User('author@example.test');
        $author->setPassword('x')->setStatus(User::STATUS_ACTIVE);
        $em->persist($author);

        $node = new Node('First title', 'revisioned', 'page', 'en');
        $node->setData(['body' => 'original body']);
        $em->persist($node);
        $em->flush();

        /** @var RevisionManager $revisions */
        $revisions = $container->get(RevisionManager::class);

        self::assertTrue($revisions->isEnabled(), 'revisions are on by default');

        // Capturing is automatic: a listener records the state on flush, so an
        // editor never has to remember to ask for a revision.
        $afterCreate = $revisions->list($node);
        self::assertCount(1, $afterCreate, 'creating a node records its first revision');
        self::assertSame('First title', $afterCreate[0]->getTitle());

        // Asking again for an unchanged node writes nothing — capture()
        // compares the snapshot hash, so repeated saves do not grow the table.
        self::assertNull($revisions->capture($node), 'an unchanged node yields no new revision');
        self::assertCount(1, $revisions->list($node));

        // A real edit produces a second revision, carrying the staged context.
        $node->setTitle('Second title');
        $node->setData(['body' => 'edited body']);
        $revisions->stageContext('after edit', $author);
        $em->flush();

        $afterEdit = $revisions->list($node);
        self::assertCount(2, $afterEdit);

        $original = array_values(array_filter(
            $afterEdit,
            static fn (NodeRevision $r): bool => $r->getTitle() === 'First title',
        ))[0] ?? null;
        self::assertInstanceOf(NodeRevision::class, $original);

        // Restoring puts the old values back on the live node.
        $revisions->restore($original, $author);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Node::class)->findOneBy(['slug' => 'revisioned']);
        self::assertInstanceOf(Node::class, $reloaded);
        self::assertSame('First title', $reloaded->getTitle());
        self::assertSame('original body', $reloaded->getData()['body'] ?? null);

        // Restoring is itself a change, so the state restored from is still
        // reachable: a restore never destroys history.
        $afterRestore = $revisions->list($reloaded);
        self::assertGreaterThanOrEqual(3, \count($afterRestore));
        self::assertContains(
            'Second title',
            array_map(static fn (NodeRevision $r): string => $r->getTitle(), $afterRestore),
            'the version restored away from is still in history',
        );
    }

    public function testPruneKeepsTheConfiguredMaximum(): void
    {
        $container = $this->container();
        $em = $this->em();

        $node = new Node('Pruned', 'pruned', 'page', 'en');
        $em->persist($node);
        $em->flush();

        /** @var RevisionManager $revisions */
        $revisions = $container->get(RevisionManager::class);
        $max = $revisions->maxPerNode();

        // Two more revisions than the ceiling, each genuinely different so none
        // is skipped by the hash comparison.
        for ($i = 0; $i < $max + 2; ++$i) {
            $node->setData(['body' => 'version '.$i]);
            $em->flush();
            $revisions->capture($node);
            $em->flush();
        }

        $revisions->prune($node);
        $em->flush();

        self::assertLessThanOrEqual(
            $max,
            \count($revisions->list($node)),
            'prune() enforces the per-node ceiling',
        );
    }
}
