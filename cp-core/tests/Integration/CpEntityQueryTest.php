<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Entity\Query\CpEntityQuery;
use App\Core\Entity\Query\CpEntityQueryFactory;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * T1.2 — the fluent, access-aware query object.
 *
 * Two properties matter more than the fluent syntax itself. First, a filter on
 * a JSON field goes through the flat index (NodeFieldIndex) rather than
 * scanning JSON, so it stays a single SQL statement. Second, accessCheck()
 * lowers capabilities into the WHERE clause instead of loading rows and asking
 * a voter about each one — the difference between one query and N+1.
 */
#[CoversClass(CpEntityQuery::class)]
#[CoversClass(CpEntityQueryFactory::class)]
final class CpEntityQueryTest extends IntegrationTestCase
{
    public function testColumnAndIndexedFieldFilters(): void
    {
        $factory = $this->seed();

        // Column filter: a plain SQL column on nodes.
        $published = $factory->forBundle('page')->where('status', '=', Node::STATUS_PUBLISHED)->getResult();
        self::assertCount(2, $published);

        // Field filter: goes through NodeFieldIndex, not a JSON scan.
        $highRank = $factory->forBundle('page')
            ->where('status', '=', Node::STATUS_PUBLISHED)
            ->whereField('rank', '>=', 5)
            ->sort('title', 'ASC')
            ->getResult();

        // Gamma also has rank 9, but it is a draft: the status filter and the
        // index filter compose, they do not override one another.
        self::assertSame(
            ['Beta'],
            array_map(static fn (Node $n): string => $n->getTitle(), $highRank),
        );

        // An unqueryable field name is refused rather than silently ignored:
        // a typo must not quietly widen the result set.
        $this->expectException(\InvalidArgumentException::class);
        $factory->forBundle('page')->whereField('not_indexed', '=', 1);
    }

    public function testOrGroupAndCountAndIds(): void
    {
        $factory = $this->seed();

        $query = $factory->forBundle('page')->orX(static function (CpEntityQuery $q): void {
            $q->where('title', '=', 'Alpha');
            $q->where('title', '=', 'Gamma');
        });

        self::assertSame(2, $query->count());

        $ids = $factory->forBundle('page')
            ->orX(static function (CpEntityQuery $q): void {
                $q->where('title', '=', 'Alpha');
                $q->where('title', '=', 'Gamma');
            })
            ->ids();

        self::assertCount(2, $ids);
        self::assertContainsOnly('int', $ids);

        // whereField() inside a group would filter the whole result through the
        // join instead of the group, so it is refused outright.
        $this->expectException(\LogicException::class);
        $factory->forBundle('page')->orX(static function (CpEntityQuery $q): void {
            $q->whereField('rank', '=', 1);
        });
    }

    public function testAccessCheckWithoutUserReturnsNothing(): void
    {
        $factory = $this->seed();

        // No authenticated user: the scope applier has no ".own" owner and no
        // ".any" grant, so the only safe answer is an empty set — never the
        // unfiltered list.
        $result = $factory->forBundle('page')->accessCheck('node.page')->getResult();

        self::assertSame([], $result);
        self::assertSame(0, $factory->forBundle('page')->accessCheck('node.page')->count());
    }

    public function testSelectorStringRoutesThroughTheEngine(): void
    {
        $factory = $this->seed();

        // ProcessWire-style selector: sugar over the same query object, not a
        // second engine. "type=" picks the bundle, which is what makes the
        // field filter resolvable.
        $result = $factory->fromSelector('type=page, rank>=5, sort=-title')->getResult();

        self::assertSame(
            ['Gamma', 'Beta'],
            array_map(static fn (Node $n): string => $n->getTitle(), $result),
        );
    }

    /**
     * Two published pages and one draft, all carrying an indexed "rank" field.
     */
    private function seed(): CpEntityQueryFactory
    {
        $container = $this->container();
        /** @var EntityManagerInterface $em */
        $em = $this->em();

        // Queryable field definitions are one of the two sources the flat index
        // draws from (the other is a module's contributions.yaml).
        $rank = new FieldDefinition('page', 'rank', 'integer', 'Rank');
        $rank->setQueryable(true);
        $em->persist($rank);
        $em->flush();

        /** @var FieldDefinitionRegistry $fields */
        $fields = $container->get(FieldDefinitionRegistry::class);
        $fields->invalidate();

        foreach ([
            ['Alpha', 'alpha', Node::STATUS_PUBLISHED, 1],
            ['Beta', 'beta', Node::STATUS_PUBLISHED, 5],
            ['Gamma', 'gamma', Node::STATUS_DRAFT, 9],
        ] as [$title, $slug, $status, $rankValue]) {
            $node = new Node($title, $slug, 'page', 'en');
            $node->setData(['rank' => $rankValue]);

            if ($status === Node::STATUS_PUBLISHED) {
                $node->publish();
            }

            $em->persist($node);
        }

        $em->flush();

        /** @var CpEntityQueryFactory $factory */
        $factory = $container->get(CpEntityQueryFactory::class);

        return $factory;
    }
}
