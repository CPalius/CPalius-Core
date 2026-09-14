<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\Destination\NodeDestination;
use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationRunner;
use App\Core\Migrate\Source\CsvSource;
use App\Entity\Node;
use App\Repository\NodeRepository;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The claim behind scorecard row 26, executed against a real database:
 * a CSV file becomes nodes in one run, a second run changes nothing, an edited
 * source updates in place instead of duplicating, and rollback removes exactly
 * what the import created.
 *
 * This is the test that would have caught the failure modes the roadmap warns
 * about — an importer that works once and duplicates on the second run is the
 * normal way this feature is shipped broken.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(DoctrineMigrationMap::class)]
#[CoversClass(NodeDestination::class)]
#[CoversClass(CsvSource::class)]
final class MigrateEndToEndTest extends IntegrationTestCase
{
    private const MIGRATION_ID = 'test.csv_posts';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testCsvBecomesNodesAndTheRunIsIdempotent(): void
    {
        $path = $this->csv("id,title,body,status\n10,First post,Hello,published\n11,Second post,World,draft\n");
        $runner = $this->runner();
        $migration = $this->migration($path);

        $first = $runner->run($migration, false);

        self::assertSame(2, $first->created());
        self::assertFalse($first->hasFailures());

        $nodes = $this->nodes();
        self::assertCount(2, $nodes);
        self::assertSame('First post', $nodes[0]->getTitle());
        self::assertSame(Node::STATUS_PUBLISHED, $nodes[0]->getStatus());
        self::assertSame(Node::STATUS_DRAFT, $nodes[1]->getStatus());
        // Anything that is not a node column lands in the hybrid model's JSON,
        // which is why an import needs no schema change per source field.
        self::assertSame('Hello', $nodes[0]->getData()['body'] ?? null);

        $second = $runner->run($this->migration($path), false);

        self::assertSame(0, $second->created(), 'A second run must not duplicate content.');
        self::assertSame(2, $second->unchanged());
        self::assertCount(2, $this->nodes());
    }

    public function testAnEditedSourceRowUpdatesTheSameNode(): void
    {
        $path = $this->csv("id,title,body,status\n10,Original,Hello,draft\n");
        $runner = $this->runner();

        $runner->run($this->migration($path), false);
        $before = $this->nodes();
        self::assertCount(1, $before);
        $originalId = $before[0]->getId();

        file_put_contents($path, "id,title,body,status\n10,Corrected,Hello again,published\n");
        $report = $runner->run($this->migration($path), false);

        self::assertSame(1, $report->updated());
        self::assertSame(0, $report->created());

        $this->em()->clear();
        $after = $this->nodes();
        self::assertCount(1, $after, 'The edited row must update in place, not add a second node.');
        self::assertSame($originalId, $after[0]->getId());
        self::assertSame('Corrected', $after[0]->getTitle());
        self::assertSame(Node::STATUS_PUBLISHED, $after[0]->getStatus());
    }

    public function testDryRunReportsTheSameWorkWithoutWritingAnything(): void
    {
        $path = $this->csv("id,title\n10,First\n11,Second\n");
        $runner = $this->runner();

        $report = $runner->run($this->migration($path), true);

        self::assertTrue($report->dryRun);
        self::assertSame(2, $report->created());
        self::assertCount(0, $this->nodes(), 'A dry run must leave the database untouched.');
        self::assertSame(0, $runner->importedCount($this->migration($path)));
    }

    public function testOneBrokenRowDoesNotStopTheImport(): void
    {
        // The middle row has no title, which the destination refuses.
        $path = $this->csv("id,title\n10,Fine\n11,\n12,Also fine\n");

        $report = $this->runner()->run($this->migration($path), false);

        self::assertSame(2, $report->created());
        self::assertSame(1, $report->failed());
        self::assertSame('11', $report->failures()[0]['sourceId']);
        self::assertCount(2, $this->nodes());
    }

    public function testCollidingTitlesGetDistinctSlugs(): void
    {
        $path = $this->csv("id,title\n10,Same title\n11,Same title\n");

        $this->runner()->run($this->migration($path), false);

        $nodes = $this->nodes();
        self::assertCount(2, $nodes);
        // uniq_node_slug_locale would otherwise turn the second row into a
        // constraint violation partway through an import.
        self::assertNotSame($nodes[0]->getSlug(), $nodes[1]->getSlug());
    }

    public function testRollbackRemovesExactlyWhatWasImported(): void
    {
        $path = $this->csv("id,title\n10,First\n11,Second\n");
        $runner = $this->runner();
        $migration = $this->migration($path);

        $runner->run($migration, false);

        // A node that did not come from the import must survive the rollback.
        $untouched = new Node('Hand written', 'hand-written', 'post', 'en');
        $this->em()->persist($untouched);
        $this->em()->flush();

        self::assertSame(2, $runner->importedCount($migration));

        $report = $runner->rollback($migration, false);

        self::assertSame(2, $report->updated(), $this->describeFailures($report));
        self::assertFalse($report->hasFailures(), $this->describeFailures($report));
        self::assertSame(0, $runner->importedCount($migration));

        $this->em()->clear();
        $remaining = $this->nodes();
        self::assertCount(1, $remaining);
        self::assertSame('Hand written', $remaining[0]->getTitle());
    }

    public function testRollbackToleratesANodeSomebodyAlreadyDeleted(): void
    {
        $path = $this->csv("id,title\n10,First\n");
        $runner = $this->runner();
        $migration = $this->migration($path);

        $runner->run($migration, false);

        // Deleted outside this process — the realistic version of "somebody
        // cleaned it up by hand", and the reason rollback cannot assume the
        // records it recorded are still there.
        $nodeIds = array_map(static fn (Node $n): ?int => $n->getId(), $this->nodes());
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM nodes WHERE id = ?',
            [$nodeIds[0]],
        );
        $this->em()->clear();

        $report = $runner->rollback($migration, false);

        // Already in the desired state is not a failure; refusing to finish
        // would leave the map claiming rows that no longer exist.
        self::assertFalse($report->hasFailures(), $this->describeFailures($report));
        self::assertSame(0, $runner->importedCount($migration));
    }

    public function testLimitImportsOnlyTheFirstRows(): void
    {
        $path = $this->csv("id,title\n10,One\n11,Two\n12,Three\n");

        $report = $this->runner()->run($this->migration($path), false, 2);

        self::assertSame(2, $report->created());
        self::assertTrue($report->limitReached());
        self::assertCount(2, $this->nodes());
    }

    private function describeFailures(\App\Core\Migrate\MigrationReport $report): string
    {
        if (!$report->hasFailures()) {
            return 'no failures reported';
        }

        return implode("\n", array_map(
            static fn (array $f): string => sprintf('source %s: %s', $f['sourceId'], $f['message']),
            $report->failures(),
        ));
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner(new DoctrineMigrationMap($this->em()));
    }

    private function migration(string $csvPath): MigrationInterface
    {
        // Built directly rather than pulled from the container: SlugGenerator
        // is a private service, and its only dependency is the repository the
        // entity manager already hands out.
        /** @var NodeRepository $nodes */
        $nodes = $this->em()->getRepository(Node::class);
        $slugGenerator = new SlugGenerator($nodes);

        return new class(self::MIGRATION_ID, new CsvSource($csvPath, 'id'), new NodeDestination($this->em(), $slugGenerator, 'post')) implements MigrationInterface {
            public function __construct(
                private readonly string $id,
                private readonly CsvSource $source,
                private readonly NodeDestination $destination,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return 'CSV posts';
            }

            public function dependsOn(): array
            {
                return [];
            }

            public function source(): CsvSource
            {
                return $this->source;
            }

            public function destination(): NodeDestination
            {
                return $this->destination;
            }

            public function transform(MigrationRow $row): MigrationRow
            {
                return $row;
            }
        };
    }

    /**
     * @return list<Node>
     */
    private function nodes(): array
    {
        /** @var list<Node> $nodes */
        $nodes = $this->em()->getRepository(Node::class)->findBy([], ['id' => 'ASC']);

        return $nodes;
    }

    private function csv(string $contents): string
    {
        $path = sys_get_temp_dir().'/cpalius-migrate-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
