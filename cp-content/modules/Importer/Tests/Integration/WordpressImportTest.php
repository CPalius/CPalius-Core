<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Integration;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationRunner;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use App\Tests\Support\IntegrationTestCase;
use Modules\Importer\Migration\Wordpress\WordpressAuthorMigration;
use Modules\Importer\Migration\Wordpress\WordpressCategoryMigration;
use Modules\Importer\Migration\Wordpress\WordpressPostMigration;
use Modules\Importer\Migration\Wordpress\WordpressTagMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A WordPress export becomes CPalius content, against a real database.
 *
 * The migrations are assembled by hand rather than pulled from the container,
 * so this proves the classes work rather than that the container can build
 * them — the wiring is covered by cp:migrate list finding all four in
 * dependency order.
 */
#[CoversClass(WordpressAuthorMigration::class)]
#[CoversClass(WordpressCategoryMigration::class)]
#[CoversClass(WordpressTagMigration::class)]
#[CoversClass(WordpressPostMigration::class)]
final class WordpressImportTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/sample-export.xml';

    private function runner(): MigrationRunner
    {
        return new MigrationRunner(new DoctrineMigrationMap($this->em()));
    }

    private function lookup(): MigrationLookup
    {
        return new MigrationLookup(new DoctrineMigrationMap($this->em()));
    }

    private function slugGenerator(): SlugGenerator
    {
        /** @var NodeRepository $nodes */
        $nodes = $this->em()->getRepository(Node::class);

        return new SlugGenerator($nodes);
    }

    /**
     * @return list<MigrationInterface>
     */
    private function chain(): array
    {
        $em = $this->em();
        $options = ['file' => self::FIXTURE, 'locale' => 'tr'];

        return [
            (new WordpressAuthorMigration($em))->withOptions(['file' => self::FIXTURE]),
            (new WordpressCategoryMigration($em, $this->lookup()))->withOptions($options),
            (new WordpressTagMigration($em))->withOptions($options),
            (new WordpressPostMigration($em, $this->slugGenerator(), $this->lookup()))->withOptions($options),
        ];
    }

    private function importAll(): void
    {
        $runner = $this->runner();

        foreach ($this->chain() as $migration) {
            $runner->run($migration, false);
        }
    }

    public function testAuthorsBecomeUsersWithUnusablePasswords(): void
    {
        $report = $this->runner()->run($this->chain()[0], false);

        self::assertSame(2, $report->created());
        self::assertSame(0, $report->skipped());

        /** @var list<User> $users */
        $users = $this->em()->getRepository(User::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $users);
        self::assertSame('admin@eski.example', $users[0]->getEmail());
        self::assertSame('Site Yöneticisi', $users[0]->getUsername());
        self::assertSame('Ali', $users[0]->getFirstName());
        self::assertStringStartsWith('!imported-', $users[0]->getPassword());
        self::assertSame('imported-wordpress-nomail@invalid.invalid', $users[1]->getEmail());
    }

    public function testCategoriesKeepTheirHierarchyOnASecondRun(): void
    {
        $runner = $this->runner();
        $categories = $this->chain()[1];

        $runner->run($categories, false);

        // The child's parent is resolved through this migration's own map, so
        // the first pass may leave it at the root; the second puts it in place.
        // Re-running is free because unchanged rows are skipped.
        $runner->run(
            (new WordpressCategoryMigration($this->em(), $this->lookup()))->withOptions(['file' => self::FIXTURE, 'locale' => 'tr']),
            false,
        );

        $this->em()->clear();
        /** @var list<Term> $terms */
        $terms = $this->em()->getRepository(Term::class)->findBy([], ['id' => 'ASC']);

        $bySlug = [];
        foreach ($terms as $term) {
            $bySlug[$term->getSlug()] = $term;
        }

        self::assertArrayHasKey('haberler', $bySlug);
        self::assertArrayHasKey('duyurular', $bySlug);
        self::assertNull($bySlug['haberler']->getParent());
        self::assertSame('haberler', $bySlug['duyurular']->getParent()?->getSlug());
    }

    public function testPostsBecomeNodesWithTheirAuthorAndTermsResolved(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<Node> $nodes */
        $nodes = $this->em()->getRepository(Node::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $nodes);

        $first = $nodes[0];
        self::assertSame('İlk Yazı', $first->getTitle());
        self::assertSame('ilk-yazi', $first->getSlug());
        self::assertSame(Node::STATUS_PUBLISHED, $first->getStatus());
        self::assertStringContainsString('Merhaba dünya', (string) ($first->getData()['body'] ?? ''));

        // Resolved through the map rather than by name.
        /** @var User $author */
        $author = $this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@eski.example']);
        self::assertSame((string) $author->getId(), (string) ($first->getData()['wordpressAuthorUserId'] ?? ''));

        self::assertSame(['haberler'], array_map(
            static fn (Term $t): string => $t->getSlug(),
            $first->getCategories()->toArray(),
        ));
        self::assertSame(['php'], array_map(
            static fn (Term $t): string => $t->getSlug(),
            $first->getTags()->toArray(),
        ));
    }

    /**
     * A private WordPress post appearing on the new public site is a
     * disclosure; discarding it is data loss. Draft is the only third option.
     */
    public function testAnythingNotPubliclyPublishedArrivesAsADraft(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<Node> $nodes */
        $nodes = $this->em()->getRepository(Node::class)->findBy([], ['id' => 'ASC']);

        self::assertSame('Özel Yazı', $nodes[1]->getTitle());
        self::assertSame(Node::STATUS_DRAFT, $nodes[1]->getStatus());
    }

    public function testPagesAreNotImportedByThePostMigration(): void
    {
        $this->importAll();
        $this->em()->clear();

        $titles = array_map(
            static fn (Node $n): string => $n->getTitle(),
            $this->em()->getRepository(Node::class)->findBy([]),
        );

        self::assertNotContains('Hakkımızda', $titles);
        self::assertNotContains('Otomatik Taslak', $titles);
    }

    public function testRunningTheWholeImportTwiceChangesNothing(): void
    {
        $this->importAll();
        $this->em()->clear();

        $before = \count($this->em()->getRepository(Node::class)->findBy([]));
        $runner = $this->runner();
        $reports = [];

        foreach ($this->chain() as $migration) {
            $reports[] = $runner->run($migration, false);
        }

        foreach ($reports as $report) {
            self::assertSame(0, $report->created(), $report->migrationId.' created rows on a second run');
            self::assertFalse($report->hasFailures(), $report->migrationId.' failed on a second run');
        }

        $this->em()->clear();
        self::assertCount($before, $this->em()->getRepository(Node::class)->findBy([]));
    }

    public function testDryRunningTheImportWritesNothing(): void
    {
        $runner = $this->runner();

        foreach ($this->chain() as $migration) {
            $runner->run($migration, true);
        }

        self::assertCount(0, $this->em()->getRepository(User::class)->findBy([]));
        self::assertCount(0, $this->em()->getRepository(Term::class)->findBy([]));
        self::assertCount(0, $this->em()->getRepository(Node::class)->findBy([]));
    }

    public function testRollingBackThePostsLeavesTheAuthorsAndTermsAlone(): void
    {
        $this->importAll();

        $posts = $this->chain()[3];
        $report = $this->runner()->rollback($posts, false);

        self::assertFalse($report->hasFailures());

        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(Node::class)->findBy([]));
        self::assertCount(2, $this->em()->getRepository(User::class)->findBy([]));
        self::assertNotCount(0, $this->em()->getRepository(Term::class)->findBy([]));
    }

    public function testAnExportThatIsNotAWordpressFileIsRefusedBeforeAnythingIsWritten(): void
    {
        $path = sys_get_temp_dir().'/cpalius-not-wxr-'.bin2hex(random_bytes(6)).'.xml';
        file_put_contents($path, '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>');

        try {
            $migration = (new WordpressAuthorMigration($this->em()))->withOptions(['file' => $path]);
            $report = $this->runner()->run($migration, false);

            // Nothing to read, so nothing is written — and no rows are invented.
            self::assertSame(0, $report->processed());
            self::assertCount(0, $this->em()->getRepository(User::class)->findBy([]));
        } finally {
            @unlink($path);
        }
    }

    public function testAnUnconfiguredImportIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WXR|\.sql|database/');

        (new WordpressAuthorMigration($this->em()))->withOptions([])->source();
    }

    public function testASqlDumpIsNotOpenedAsXml(): void
    {
        $path = sys_get_temp_dir().'/cpalius-wp-'.bin2hex(random_bytes(6)).'.sql';
        file_put_contents($path, "-- MySQL dump\nCREATE TABLE wp_users (ID int);\n");

        try {
            $origin = new \Modules\Importer\Source\Wordpress\WordpressOrigin($this->em(), ['file' => $path]);
            self::assertTrue($origin->isDatabase());
            self::assertSame($path, $origin->dumpPath());
        } finally {
            @unlink($path);
        }
    }

    public function testAWordpressDatabaseBecomesAuthorsAndPosts(): void
    {
        $source = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source->executeStatement('CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT, display_name TEXT)');
        $source->executeStatement('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_author INTEGER, post_date TEXT, post_content TEXT, post_title TEXT, post_excerpt TEXT, post_status TEXT, post_name TEXT, post_type TEXT, guid TEXT, post_parent INTEGER, menu_order INTEGER)');
        $source->executeStatement('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT)');
        $source->executeStatement('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, description TEXT, parent INTEGER)');
        $source->executeStatement('CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)');
        $source->insert('wp_users', ['ID' => 1, 'user_login' => 'editor', 'user_email' => 'editor@wp.test', 'display_name' => 'Editör']);
        $source->insert('wp_terms', ['term_id' => 1, 'name' => 'Haber', 'slug' => 'haber']);
        $source->insert('wp_term_taxonomy', ['term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category', 'description' => '', 'parent' => 0]);
        $source->insert('wp_posts', ['ID' => 10, 'post_author' => 1, 'post_date' => '2024-01-02 10:00:00', 'post_content' => 'Merhaba SQL', 'post_title' => 'Veritabanı yazısı', 'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'veritabani-yazisi', 'post_type' => 'post', 'guid' => 'http://eski.test/?p=10', 'post_parent' => 0, 'menu_order' => 0]);
        $source->insert('wp_term_relationships', ['object_id' => 10, 'term_taxonomy_id' => 1]);

        $db = \App\Core\Migrate\Source\ForeignDatabase::wrap($source, 'wp_');
        $em = $this->em();
        $runner = $this->runner();
        $lookup = $this->lookup();

        $runner->run(new WordpressAuthorMigration($em, [], $db), false);
        $runner->run(new WordpressCategoryMigration($em, $lookup, [], $db), false);
        $runner->run(new WordpressTagMigration($em, [], $db), false);
        $runner->run(new WordpressPostMigration($em, $this->slugGenerator(), $lookup, [], $db), false);
        $this->em()->clear();

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'editor@wp.test']);
        self::assertSame('Editör', $user?->getUsername());
        $node = $this->em()->getRepository(Node::class)->findOneBy(['title' => 'Veritabanı yazısı']);
        self::assertNotNull($node);
        self::assertStringContainsString('Merhaba SQL', (string) ($node->getData()['body'] ?? ''));
        self::assertSame(['haber'], array_map(
            static fn (Term $t): string => $t->getSlug(),
            $node->getCategories()->toArray(),
        ));
    }

    public function testAMistypedOptionIsRefusedRatherThanIgnored(): void
    {
        // Accepting it silently would run the import against the default and
        // look exactly like the option having had no effect.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown option.*fiel/s');

        (new WordpressAuthorMigration($this->em()))->withOptions(['fiel' => self::FIXTURE]);
    }
}
