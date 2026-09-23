<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Integration;

use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationRunner;
use App\Core\Migrate\Source\ForeignDatabase;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\ForumNodeType;
use Modules\Forum\Entity\ForumSmilie;
use Modules\Forum\Service\ForumStatsService;
use Modules\Importer\Migration\Xenforo\XenforoPostMigration;
use Modules\Importer\Migration\Xenforo\XenforoSectionMigration;
use Modules\Importer\Migration\Xenforo\XenforoSmilieMigration;
use Modules\Importer\Migration\Xenforo\XenforoTopicMigration;
use Modules\Importer\Migration\Xenforo\XenforoUserMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A XenForo board becomes a CPalius forum.
 *
 * WHAT THIS DOES AND DOES NOT PROVE
 * The source is a SQLite database built to XenForo's documented table shape and
 * seeded with a small board. That proves the mapping: which column becomes
 * which field, how the tree is rebuilt, what happens to dates, markup, and
 * rows whose parent did not come across. It does NOT prove compatibility with
 * every real installation — versions differ, add-ons add columns, and boards
 * accumulate oddities no fixture predicts. The first run against a real export
 * is still a real test, which is exactly why the importer dry-runs by default
 * and reports failures by source id.
 */
#[CoversClass(XenforoUserMigration::class)]
#[CoversClass(XenforoSectionMigration::class)]
#[CoversClass(XenforoTopicMigration::class)]
#[CoversClass(XenforoPostMigration::class)]
#[CoversClass(XenforoSmilieMigration::class)]
final class XenforoImportTest extends IntegrationTestCase
{
    private ?Connection $source = null;

    protected function tearDown(): void
    {
        $this->source = null;

        parent::tearDown();
    }

    /**
     * XenForo's shape, as the reference importer documents it: one node tree
     * with per-type side tables, threads under nodes, posts under threads.
     */
    private function source(): Connection
    {
        if ($this->source !== null) {
            return $this->source;
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $connection->executeStatement('CREATE TABLE xf_user (user_id INTEGER PRIMARY KEY, username TEXT, email TEXT, register_date INTEGER, user_state TEXT, is_staff INTEGER)');
        $connection->executeStatement('CREATE TABLE xf_user_profile (user_id INTEGER PRIMARY KEY, website TEXT, signature TEXT)');
        $connection->executeStatement('CREATE TABLE xf_node (node_id INTEGER PRIMARY KEY, title TEXT, description TEXT, node_type_id TEXT, parent_node_id INTEGER, display_order INTEGER)');
        $connection->executeStatement('CREATE TABLE xf_forum (node_id INTEGER PRIMARY KEY, allow_posting INTEGER)');
        $connection->executeStatement('CREATE TABLE xf_link_forum (node_id INTEGER PRIMARY KEY, link_url TEXT)');
        $connection->executeStatement('CREATE TABLE xf_thread (thread_id INTEGER PRIMARY KEY, node_id INTEGER, title TEXT, user_id INTEGER, username TEXT, post_date INTEGER, last_post_date INTEGER, view_count INTEGER, reply_count INTEGER, sticky INTEGER, discussion_open INTEGER, discussion_state TEXT)');
        $connection->executeStatement('CREATE TABLE xf_post (post_id INTEGER PRIMARY KEY, thread_id INTEGER, user_id INTEGER, username TEXT, post_date INTEGER, message TEXT, message_state TEXT)');
        $connection->executeStatement('CREATE TABLE xf_smilie (smilie_id INTEGER PRIMARY KEY, title TEXT, smilie_text TEXT, image_url TEXT, smilie_category_id INTEGER, display_order INTEGER, display_in_editor INTEGER)');

        // Two members, one of them without an address.
        $connection->insert('xf_user', ['user_id' => 1, 'username' => 'ali', 'email' => 'ali@eski.test', 'register_date' => 1600000000, 'user_state' => 'valid', 'is_staff' => 1]);
        $connection->insert('xf_user_profile', ['user_id' => 1, 'website' => 'https://ali.test', 'signature' => '[b]imza[/b]']);
        $connection->insert('xf_user', ['user_id' => 2, 'username' => 'nomail', 'email' => '', 'register_date' => 1600000001, 'user_state' => 'valid', 'is_staff' => 0]);

        // A category holding one forum, plus a link node and a page node.
        $connection->insert('xf_node', ['node_id' => 1, 'title' => 'Genel', 'description' => 'Kategori', 'node_type_id' => 'Category', 'parent_node_id' => 0, 'display_order' => 1]);
        $connection->insert('xf_node', ['node_id' => 2, 'title' => 'Duyurular', 'description' => 'Forum', 'node_type_id' => 'Forum', 'parent_node_id' => 1, 'display_order' => 2]);
        $connection->insert('xf_forum', ['node_id' => 2, 'allow_posting' => 1]);
        $connection->insert('xf_node', ['node_id' => 3, 'title' => 'Dış bağlantı', 'description' => '', 'node_type_id' => 'LinkForum', 'parent_node_id' => 1, 'display_order' => 3]);
        $connection->insert('xf_link_forum', ['node_id' => 3, 'link_url' => 'https://example.test']);
        $connection->insert('xf_node', ['node_id' => 4, 'title' => 'Sayfa', 'description' => '', 'node_type_id' => 'Page', 'parent_node_id' => 1, 'display_order' => 4]);

        // One visible thread in the forum, one thread under the page node.
        $connection->insert('xf_thread', ['thread_id' => 10, 'node_id' => 2, 'title' => 'İlk konu', 'user_id' => 1, 'username' => 'ali', 'post_date' => 1600100000, 'last_post_date' => 1600200000, 'view_count' => 42, 'reply_count' => 1, 'sticky' => 1, 'discussion_open' => 0, 'discussion_state' => 'visible']);
        $connection->insert('xf_thread', ['thread_id' => 11, 'node_id' => 4, 'title' => 'Sayfa altı konu', 'user_id' => 1, 'username' => 'ali', 'post_date' => 1600100001, 'last_post_date' => 1600100001, 'view_count' => 0, 'reply_count' => 0, 'sticky' => 0, 'discussion_open' => 1, 'discussion_state' => 'visible']);

        $connection->insert('xf_smilie', ['smilie_id' => 1, 'title' => 'Smile', 'smilie_text' => ":)\n:-)", 'image_url' => 'data/smilies/smile.png', 'smilie_category_id' => 1, 'display_order' => 10, 'display_in_editor' => 1]);
        $connection->insert('xf_smilie', ['smilie_id' => 2, 'title' => 'Cool', 'smilie_text' => ':cool:', 'image_url' => 'https://cdn.old.test/cool.png', 'smilie_category_id' => 1, 'display_order' => 20, 'display_in_editor' => 1]);

        $connection->insert('xf_post', ['post_id' => 100, 'thread_id' => 10, 'user_id' => 1, 'username' => 'ali', 'post_date' => 1600100000, 'message' => "[b]Merhaba[/b] :)\n\n[url=https://example.test]bak[/url]", 'message_state' => 'visible']);
        $connection->insert('xf_post', ['post_id' => 101, 'thread_id' => 10, 'user_id' => 0, 'username' => 'Misafir', 'post_date' => 1600200000, 'message' => 'ikinci', 'message_state' => 'moderated']);
        $connection->insert('xf_post', ['post_id' => 102, 'thread_id' => 11, 'user_id' => 1, 'username' => 'ali', 'post_date' => 1600100001, 'message' => 'yetim', 'message_state' => 'visible']);
        $connection->insert('xf_post', ['post_id' => 103, 'thread_id' => 10, 'user_id' => 2, 'username' => 'nomail', 'post_date' => 1600200001, 'message' => 'adresim yok ama yazdım', 'message_state' => 'visible']);

        return $this->source = $connection;
    }

    private function database(): ForeignDatabase
    {
        return ForeignDatabase::wrap($this->source(), 'xf_');
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

        $runner->run(new XenforoUserMigration($em, ['role' => 'member'], $db), false);
        $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        // Twice: a child whose parent had not landed yet finds it on the second
        // pass, and unchanged rows cost nothing.
        $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $runner->run(new XenforoTopicMigration($em, $this->lookup(), [], $db), false);
        $runner->run(new XenforoPostMigration($em, $this->lookup(), [], $db), false);
    }

    public function testMembersBecomeUsersEvenWithoutAnAddress(): void
    {
        $report = $this->runner()->run(new XenforoUserMigration($this->em(), [], $this->database()), false);

        self::assertSame(2, $report->created());
        self::assertSame(0, $report->skipped());

        /** @var User $user */
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'ali@eski.test']);
        self::assertSame('ali', $user->getUsername());
        self::assertStringStartsWith('!imported-', $user->getPassword(), 'no foreign hash is carried over');
        // Signatures are BBCode in every forum package.
        self::assertStringContainsString('<strong>imza</strong>', (string) ($user->getData()['signature'] ?? ''));

        /** @var User $nomail */
        $nomail = $this->em()->getRepository(User::class)->findOneBy(['username' => 'nomail']);
        self::assertSame('imported-xenforo-2@invalid.invalid', $nomail->getEmail());
        self::assertSame('1', (string) ($nomail->getData()['emailPlaceholder'] ?? ''));
    }

    public function testTheNodeTreeIsRebuiltWithCategoriesForumsAndLinks(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<ForumSection> $sections */
        $sections = $this->em()->getRepository(ForumSection::class)->findBy([], ['id' => 'ASC']);

        $byCode = [];
        foreach ($sections as $section) {
            $byCode[$section->getCode()] = $section;
        }

        // The Page node has no forum section to become and is left out.
        self::assertCount(3, $sections);
        self::assertArrayNotHasKey('xenforo-4', $byCode);

        self::assertSame(ForumNodeType::Category, $byCode['xenforo-1']->getNodeType());
        self::assertSame(ForumNodeType::Forum, $byCode['xenforo-2']->getNodeType());
        self::assertSame(ForumNodeType::Link, $byCode['xenforo-3']->getNodeType());
        self::assertSame('https://example.test', $byCode['xenforo-3']->getLinkUrl());

        // The tree, resolved through the map rather than by matching names.
        self::assertNull($byCode['xenforo-1']->getParent());
        self::assertSame('xenforo-1', $byCode['xenforo-2']->getParent()?->getCode());
    }

    /**
     * The whole point of a forum archive is when things were said. An import
     * that stamps everything with the migration date keeps the words and
     * throws away the conversation.
     */
    public function testTopicsAndPostsKeepTheirOriginalDates(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var ForumTopic $topic */
        $topic = $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'İlk konu']);
        self::assertSame('2020-09-14', $topic->getCreatedAt()->format('Y-m-d'));

        /** @var list<ForumPost> $posts */
        $posts = $this->em()->getRepository(ForumPost::class)->findBy([], ['id' => 'ASC']);
        self::assertSame('2020-09-14', $posts[0]->getCreatedAt()->format('Y-m-d'));
    }

    public function testATopicKeepsItsCountsAuthorAndFlags(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var ForumTopic $topic */
        $topic = $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'İlk konu']);

        self::assertSame(42, $topic->getViewCount());
        self::assertTrue($topic->isSticky());
        // XenForo stores "discussion_open"; CPalius stores "locked", so this is
        // the inverse rather than a copy.
        self::assertTrue($topic->isLocked());
        self::assertSame('ali@eski.test', $topic->getFirstPoster()?->getEmail());
    }

    public function testPostBodiesAreConvertedFromBbcodeToHtml(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<ForumPost> $posts */
        $posts = $this->em()->getRepository(ForumPost::class)->findBy([], ['id' => 'ASC']);

        self::assertStringContainsString('<strong>Merhaba</strong>', $posts[0]->getBody());
        self::assertStringContainsString('href="https://example.test"', $posts[0]->getBody());
        self::assertStringNotContainsString('[b]', $posts[0]->getBody());
        // XenForo keeps the trigger in the stored post. Replacing it here
        // would make a later catalog change invisible, and would rewrite
        // every imported row for a picture.
        self::assertStringContainsString(':)', $posts[0]->getBody());
    }

    public function testSmiliesLandInTheCatalogWithResolvedImages(): void
    {
        $report = $this->runner()->run(new XenforoSmilieMigration($this->em(), ['siteUrl' => 'https://eski.test'], $this->database()), false);

        self::assertSame(2, $report->created());
        self::assertFalse($report->hasFailures());

        /** @var ForumSmilie $smile */
        $smile = $this->em()->getRepository(ForumSmilie::class)->findOneBy(['code' => ':)']);
        self::assertSame('https://eski.test/data/smilies/smile.png', $smile->getImageUrl());
        self::assertSame([':-)'], $smile->getExtraCodes());
        self::assertSame('😊', $smile->getEmoji());
        self::assertSame('xenforo', $smile->getImportedFrom());

        /** @var ForumSmilie $cool */
        $cool = $this->em()->getRepository(ForumSmilie::class)->findOneBy(['code' => ':cool:']);
        self::assertSame('https://cdn.old.test/cool.png', $cool->getImageUrl());
        self::assertSame('😎', $cool->getEmoji());
    }

    public function testAModeratedPostStaysOutOfSight(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<ForumPost> $posts */
        $posts = $this->em()->getRepository(ForumPost::class)->findBy([], ['id' => 'ASC']);

        self::assertSame(ForumDiscussionState::Visible, $posts[0]->getDiscussionState());
        // What the old board had hidden stays hidden: reappearing on the new
        // one would be a disclosure, and the decision was already made.
        self::assertSame(ForumDiscussionState::Moderated, $posts[1]->getDiscussionState());
    }

    public function testAPostByAGuestKeepsTheNameAndGetsNoAccount(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<ForumPost> $posts */
        $posts = $this->em()->getRepository(ForumPost::class)->findBy([], ['id' => 'ASC']);

        self::assertSame('Misafir', $posts[1]->getPosterName());
        self::assertNull($posts[1]->getAuthor(), 'forums are full of posts by accounts that no longer exist');
    }

    public function testAMemberWithoutAnAddressStillGetsAProfileLinkOnTheirPosts(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var ForumPost $post */
        $post = $this->em()->getRepository(ForumPost::class)->findOneBy(['posterName' => 'nomail']);
        self::assertSame('nomail', $post->getAuthor()?->getUsername());
        self::assertSame('imported-xenforo-2@invalid.invalid', $post->getAuthor()?->getEmail());
    }

    public function testRebuildRefreshesPosterNamesAfterAUsernameChange(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var User $user */
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'ali@eski.test']);
        $user->setUsername('ali-yeni');
        $this->em()->flush();

        /** @var ForumTopic $topic */
        $topic = $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'İlk konu']);
        $this->container()->get(ForumStatsService::class)
            ->rebuildTopicsByIds([(int) $topic->getId()]);
        $this->em()->clear();

        $renamed = $this->em()->getRepository(ForumPost::class)->findOneBy(['posterName' => 'ali-yeni']);
        self::assertNotNull($renamed);
        self::assertStringContainsString('Merhaba', $renamed->getBody());
        self::assertSame('ali-yeni', $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'İlk konu'])?->getFirstPosterName());
    }

    /**
     * Not an error: the section migration deliberately leaves out node types
     * that are not forums, so threads under them have nowhere to go.
     */
    public function testThreadsUnderAnUnimportedNodeAreSkippedNotFailed(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();

        $runner->run(new XenforoUserMigration($em, [], $db), false);
        $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $report = $runner->run(new XenforoTopicMigration($em, $this->lookup(), [], $db), false);

        self::assertSame(1, $report->created());
        self::assertSame(1, $report->skipped());
        self::assertSame(0, $report->failed());
    }

    public function testReimportingWithADifferentLocaleMovesTheBoardInPlace(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();

        $runner->run(new XenforoUserMigration($em, [], $db), false);
        $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'en'], $db), false);
        $runner->run(new XenforoTopicMigration($em, $this->lookup(), ['locale' => 'en'], $db), false);

        $this->em()->clear();
        self::assertSame('en', $this->em()->getRepository(ForumSection::class)->findOneBy(['code' => 'xenforo-2'])?->getLocale());
        self::assertSame('en', $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'İlk konu'])?->getLocale());

        $sectionReport = $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $topicReport = $runner->run(new XenforoTopicMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);

        self::assertGreaterThan(0, $sectionReport->updated());
        self::assertGreaterThan(0, $topicReport->updated());

        $this->em()->clear();
        self::assertSame('tr', $this->em()->getRepository(ForumSection::class)->findOneBy(['code' => 'xenforo-2'])?->getLocale());
        self::assertSame('tr', $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'İlk konu'])?->getLocale());
        self::assertCount(3, $this->em()->getRepository(ForumSection::class)->findBy([]), 'a locale change must not duplicate the tree');
    }

    public function testRunningTheWholeImportTwiceChangesNothing(): void
    {
        $this->importAll();
        $this->em()->clear();
        $before = \count($this->em()->getRepository(ForumPost::class)->findBy([]));

        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();
        $reports = [
            $runner->run(new XenforoUserMigration($em, [], $db), false),
            $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false),
            $runner->run(new XenforoTopicMigration($em, $this->lookup(), [], $db), false),
            $runner->run(new XenforoPostMigration($em, $this->lookup(), [], $db), false),
        ];

        foreach ($reports as $report) {
            self::assertSame(0, $report->created(), $report->migrationId.' created rows on a second run');
            self::assertFalse($report->hasFailures(), $report->migrationId.' failed on a second run');
        }

        $this->em()->clear();
        self::assertCount($before, $this->em()->getRepository(ForumPost::class)->findBy([]));
    }

    public function testADryRunOfTheWholeBoardWritesNothing(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();

        $runner->run(new XenforoUserMigration($em, [], $db), true);
        $runner->run(new XenforoSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), true);

        self::assertCount(0, $this->em()->getRepository(ForumSection::class)->findBy([]));
        self::assertCount(0, $this->em()->getRepository(User::class)->findBy([]));
    }

    public function testAWrongPrefixIsReportedAgainstWhatTheOperatorTyped(): void
    {
        $migration = new XenforoUserMigration($this->em(), [], ForeignDatabase::wrap($this->source(), 'wrong_'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Check the table prefix \("wrong_"\)/');

        $migration->source();
    }
}
