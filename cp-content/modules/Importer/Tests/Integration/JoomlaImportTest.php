<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Integration;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationRunner;
use App\Core\Migrate\Source\ForeignDatabase;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Modules\Importer\Migration\Joomla\JoomlaArticleMigration;
use Modules\Importer\Migration\Joomla\JoomlaCategoryMigration;
use Modules\Importer\Migration\Joomla\JoomlaUserMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A Joomla site becomes CPalius content.
 *
 * The source is SQLite built to Joomla's documented table shape. That proves
 * the mapping, not compatibility with every real installation — see
 * XenforoImportTest for the same caveat stated at length.
 */
#[CoversClass(JoomlaUserMigration::class)]
#[CoversClass(JoomlaCategoryMigration::class)]
#[CoversClass(JoomlaArticleMigration::class)]
final class JoomlaImportTest extends IntegrationTestCase
{
    /** Joomla randomises its prefix at install time; this is one. */
    private const PREFIX = 'x7k2p_';

    private ?Connection $source = null;

    protected function tearDown(): void
    {
        $this->source = null;

        parent::tearDown();
    }

    private function source(): Connection
    {
        if ($this->source !== null) {
            return $this->source;
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $p = self::PREFIX;

        $connection->executeStatement("CREATE TABLE {$p}users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, email TEXT, registerDate TEXT, block INTEGER)");
        $connection->executeStatement("CREATE TABLE {$p}categories (id INTEGER PRIMARY KEY, parent_id INTEGER, title TEXT, alias TEXT, description TEXT, published INTEGER, extension TEXT)");
        $connection->executeStatement("CREATE TABLE {$p}content (id INTEGER PRIMARY KEY, title TEXT, alias TEXT, introtext TEXT, fulltext TEXT, state INTEGER, catid INTEGER, created TEXT, created_by INTEGER, publish_up TEXT)");

        $connection->insert($p.'users', ['id' => 100, 'name' => 'Ali Yazar', 'username' => 'ali', 'email' => 'ali@joomla.test', 'registerDate' => '2021-05-01 10:00:00', 'block' => 0]);
        $connection->insert($p.'users', ['id' => 101, 'name' => 'Epostasiz', 'username' => 'nomail', 'email' => '', 'registerDate' => '2021-05-02 10:00:00', 'block' => 0]);

        // Joomla's id 1 is the nested-set root, not a real category.
        $connection->insert($p.'categories', ['id' => 1, 'parent_id' => 0, 'title' => 'ROOT', 'alias' => 'root', 'description' => '', 'published' => 1, 'extension' => 'system']);
        $connection->insert($p.'categories', ['id' => 2, 'parent_id' => 1, 'title' => 'Haberler', 'alias' => 'haberler', 'description' => 'Haber kategorisi', 'published' => 1, 'extension' => 'com_content']);
        $connection->insert($p.'categories', ['id' => 3, 'parent_id' => 2, 'title' => 'Duyurular', 'alias' => 'duyurular', 'description' => '', 'published' => 1, 'extension' => 'com_content']);
        // Belongs to another extension and is not an article category.
        $connection->insert($p.'categories', ['id' => 4, 'parent_id' => 1, 'title' => 'Kişiler', 'alias' => 'kisiler', 'description' => '', 'published' => 1, 'extension' => 'com_contact']);

        // state: 1 published, 0 unpublished, 2 archived, -2 trashed.
        $connection->insert($p.'content', ['id' => 200, 'title' => 'Yayında', 'alias' => 'yayinda', 'introtext' => '<p>Giriş.</p>', 'fulltext' => '<p>Devamı.</p>', 'state' => 1, 'catid' => 2, 'created' => '2021-06-01 09:00:00', 'created_by' => 100, 'publish_up' => '2021-06-02 09:00:00']);
        $connection->insert($p.'content', ['id' => 201, 'title' => 'Arşivlenmiş', 'alias' => 'arsiv', 'introtext' => 'sadece giriş', 'fulltext' => '', 'state' => 2, 'catid' => 3, 'created' => '2021-06-03 09:00:00', 'created_by' => 100, 'publish_up' => '0000-00-00 00:00:00']);

        return $this->source = $connection;
    }

    private function database(): ForeignDatabase
    {
        return ForeignDatabase::wrap($this->source(), self::PREFIX);
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner(new DoctrineMigrationMap($this->em()));
    }

    private function lookup(): MigrationLookup
    {
        return new MigrationLookup(new DoctrineMigrationMap($this->em()));
    }

    private function importAll(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();
        /** @var NodeRepository $nodes */
        $nodes = $em->getRepository(Node::class);

        $runner->run(new JoomlaUserMigration($em, [], $db), false);
        $runner->run(new JoomlaCategoryMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $runner->run(new JoomlaCategoryMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $runner->run(new JoomlaArticleMigration($em, new SlugGenerator($nodes), $this->lookup(), ['locale' => 'tr'], $db), false);
    }

    public function testUsersBecomeAccountsAndOnesWithoutAnAddressAreSkipped(): void
    {
        $report = $this->runner()->run(new JoomlaUserMigration($this->em(), [], $this->database()), false);

        self::assertSame(1, $report->created());
        self::assertSame(1, $report->skipped());

        /** @var User $user */
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'ali@joomla.test']);
        // The display name is what a reader sees, so it wins over the login.
        self::assertSame('Ali Yazar', $user->getUsername());
    }

    /**
     * Joomla keeps categories for every extension in one table, and its id 1 is
     * a nested-set anchor rather than a category. Importing either would fill
     * the vocabulary with contact groups under a term called ROOT.
     */
    public function testOnlyArticleCategoriesAreImportedAndNotTheNestedSetRoot(): void
    {
        $this->importAll();
        $this->em()->clear();

        $slugs = array_map(
            static fn (Term $t): string => $t->getSlug(),
            $this->em()->getRepository(Term::class)->findBy([]),
        );

        self::assertContains('haberler', $slugs);
        self::assertContains('duyurular', $slugs);
        self::assertNotContains('root', $slugs);
        self::assertNotContains('kisiler', $slugs);
    }

    public function testTheCategoryTreeIsRebuiltWithTheJoomlaRootTreatedAsNoParent(): void
    {
        $this->importAll();
        $this->em()->clear();

        $bySlug = [];
        foreach ($this->em()->getRepository(Term::class)->findBy([]) as $term) {
            $bySlug[$term->getSlug()] = $term;
        }

        // Its Joomla parent is the root row, so here it is a root category.
        self::assertNull($bySlug['haberler']->getParent());
        self::assertSame('haberler', $bySlug['duyurular']->getParent()?->getSlug());
    }

    /**
     * Joomla splits a body in two at the read-more break. Importing only the
     * intro would silently truncate every long article on the site.
     */
    public function testIntroAndFullTextAreJoinedBackIntoOneBody(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var Node $node */
        $node = $this->em()->getRepository(Node::class)->findOneBy(['title' => 'Yayında']);
        $body = (string) ($node->getData()['body'] ?? '');

        self::assertStringContainsString('Giriş.', $body);
        self::assertStringContainsString('Devamı.', $body);
    }

    public function testOnlyPublishedArticlesArePublishedAndTheRestAreDrafts(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var Node $published */
        $published = $this->em()->getRepository(Node::class)->findOneBy(['title' => 'Yayında']);
        /** @var Node $archived */
        $archived = $this->em()->getRepository(Node::class)->findOneBy(['title' => 'Arşivlenmiş']);

        self::assertSame(Node::STATUS_PUBLISHED, $published->getStatus());
        // Archived is neither published nor discarded: publishing what was
        // hidden is a disclosure, dropping it is data loss.
        self::assertSame(Node::STATUS_DRAFT, $archived->getStatus());
    }

    public function testAnArticleIsFiledUnderItsCategoryAndKeepsItsAuthor(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var Node $node */
        $node = $this->em()->getRepository(Node::class)->findOneBy(['title' => 'Yayında']);
        /** @var User $author */
        $author = $this->em()->getRepository(User::class)->findOneBy(['email' => 'ali@joomla.test']);

        self::assertSame(['haberler'], array_map(
            static fn (Term $t): string => $t->getSlug(),
            $node->getCategories()->toArray(),
        ));
        self::assertSame((string) $author->getId(), (string) ($node->getData()['joomlaAuthorUserId'] ?? ''));
    }

    /**
     * publish_up is when it went live and is what a reader means by the date on
     * an article; Joomla writes zeroes when it was never set, and that must not
     * become a date in the year zero.
     */
    public function testThePublishDateIsUsedAndJoomlaZeroDatesAreIgnored(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var Node $published */
        $published = $this->em()->getRepository(Node::class)->findOneBy(['title' => 'Yayında']);

        self::assertSame('2021-06-02', $published->getPublishedAt()?->format('Y-m-d'));
    }

    public function testRunningTheWholeImportTwiceChangesNothing(): void
    {
        $this->importAll();
        $this->em()->clear();
        $before = \count($this->em()->getRepository(Node::class)->findBy([]));

        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();
        /** @var NodeRepository $nodes */
        $nodes = $em->getRepository(Node::class);

        $reports = [
            $runner->run(new JoomlaUserMigration($em, [], $db), false),
            $runner->run(new JoomlaCategoryMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false),
            $runner->run(new JoomlaArticleMigration($em, new SlugGenerator($nodes), $this->lookup(), ['locale' => 'tr'], $db), false),
        ];

        foreach ($reports as $report) {
            self::assertSame(0, $report->created(), $report->migrationId.' created rows on a second run');
            self::assertFalse($report->hasFailures(), $report->migrationId.' failed on a second run');
        }

        $this->em()->clear();
        self::assertCount($before, $this->em()->getRepository(Node::class)->findBy([]));
    }

    /**
     * Joomla randomises its prefix at install time, so getting it wrong is the
     * most likely first mistake and has to say so.
     */
    public function testAWrongPrefixIsReportedAgainstWhatTheOperatorTyped(): void
    {
        $migration = new JoomlaUserMigration($this->em(), [], ForeignDatabase::wrap($this->source(), 'jos_'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Check the table prefix \("jos_"\)/');

        $migration->source();
    }
}
