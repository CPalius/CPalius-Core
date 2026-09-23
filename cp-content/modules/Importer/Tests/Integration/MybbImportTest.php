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
use Modules\Importer\Migration\Mybb\MybbPostMigration;
use Modules\Importer\Migration\Mybb\MybbSectionMigration;
use Modules\Importer\Migration\Mybb\MybbTopicMigration;
use Modules\Importer\Migration\Mybb\MybbUserMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A MyBB board becomes a CPalius forum.
 *
 * The source is SQLite built to MyBB's documented table shape. That proves the
 * mapping, not compatibility with every real installation — see
 * XenforoImportTest for the same caveat stated at length.
 */
#[CoversClass(MybbUserMigration::class)]
#[CoversClass(MybbSectionMigration::class)]
#[CoversClass(MybbTopicMigration::class)]
#[CoversClass(MybbPostMigration::class)]
final class MybbImportTest extends IntegrationTestCase
{
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

        $connection->executeStatement('CREATE TABLE mybb_users (uid INTEGER PRIMARY KEY, username TEXT, email TEXT, regdate INTEGER, postnum INTEGER, signature TEXT, website TEXT, avatar TEXT)');
        $connection->executeStatement('CREATE TABLE mybb_forums (fid INTEGER PRIMARY KEY, pid INTEGER, name TEXT, description TEXT, type TEXT, disporder INTEGER, open INTEGER, linkto TEXT)');
        $connection->executeStatement('CREATE TABLE mybb_threads (tid INTEGER PRIMARY KEY, fid INTEGER, subject TEXT, uid INTEGER, username TEXT, dateline INTEGER, lastpost INTEGER, views INTEGER, replies INTEGER, sticky INTEGER, closed TEXT, visible INTEGER)');
        $connection->executeStatement('CREATE TABLE mybb_posts (pid INTEGER PRIMARY KEY, tid INTEGER, uid INTEGER, username TEXT, dateline INTEGER, message TEXT, visible INTEGER)');

        $connection->insert('mybb_users', ['uid' => 1, 'username' => 'admin', 'email' => 'admin@mybb.test', 'regdate' => 1500000000, 'postnum' => 10, 'signature' => '[i]imza[/i]', 'website' => '']);
        $connection->insert('mybb_users', ['uid' => 2, 'username' => 'nomail', 'email' => '', 'regdate' => 1500000001, 'postnum' => 0, 'signature' => '', 'website' => '']);

        // A category, a forum inside it, and a link.
        $connection->insert('mybb_forums', ['fid' => 1, 'pid' => 0, 'name' => 'Kategori', 'description' => '', 'type' => 'c', 'disporder' => 1, 'open' => 1, 'linkto' => '']);
        $connection->insert('mybb_forums', ['fid' => 2, 'pid' => 1, 'name' => 'Sohbet', 'description' => 'Genel sohbet', 'type' => 'f', 'disporder' => 2, 'open' => 1, 'linkto' => '']);
        // MyBB stores a link as an ordinary forum that happens to have a target.
        $connection->insert('mybb_forums', ['fid' => 3, 'pid' => 1, 'name' => 'Bağlantı', 'description' => '', 'type' => 'f', 'disporder' => 3, 'open' => 1, 'linkto' => 'https://example.test']);

        // visible: 1 shown, 0 waiting for a moderator, -1 soft-deleted.
        $connection->insert('mybb_threads', ['tid' => 10, 'fid' => 2, 'subject' => 'Açık konu', 'uid' => 1, 'username' => 'admin', 'dateline' => 1500100000, 'lastpost' => 1500200000, 'views' => 7, 'replies' => 1, 'sticky' => 0, 'closed' => '0', 'visible' => 1]);
        // "moved|11" is what MyBB leaves behind for a moved thread, and it is
        // why "closed" cannot simply be compared to "1".
        $connection->insert('mybb_threads', ['tid' => 11, 'fid' => 2, 'subject' => 'Taşınmış konu', 'uid' => 1, 'username' => 'admin', 'dateline' => 1500100002, 'lastpost' => 1500100002, 'views' => 0, 'replies' => 0, 'sticky' => 1, 'closed' => 'moved|10', 'visible' => 1]);
        $connection->insert('mybb_threads', ['tid' => 12, 'fid' => 99, 'subject' => 'Yetim konu', 'uid' => 1, 'username' => 'admin', 'dateline' => 1500100003, 'lastpost' => 1500100003, 'views' => 0, 'replies' => 0, 'sticky' => 0, 'closed' => '0', 'visible' => 1]);

        $connection->insert('mybb_posts', ['pid' => 100, 'tid' => 10, 'uid' => 1, 'username' => 'admin', 'dateline' => 1500100000, 'message' => '[b]kalın[/b] ve [url]https://example.test[/url]', 'visible' => 1]);
        $connection->insert('mybb_posts', ['pid' => 101, 'tid' => 10, 'uid' => 0, 'username' => 'Misafir', 'dateline' => 1500200000, 'message' => 'onay bekliyor', 'visible' => 0]);
        $connection->insert('mybb_posts', ['pid' => 102, 'tid' => 10, 'uid' => 1, 'username' => 'admin', 'dateline' => 1500200001, 'message' => 'silinmiş', 'visible' => -1]);

        return $this->source = $connection;
    }

    private function database(): ForeignDatabase
    {
        return ForeignDatabase::wrap($this->source(), 'mybb_');
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

        $runner->run(new MybbUserMigration($em, [], $db), false);
        $runner->run(new MybbSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $runner->run(new MybbSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $runner->run(new MybbTopicMigration($em, $this->lookup(), [], $db), false);
        $runner->run(new MybbPostMigration($em, $this->lookup(), [], $db), false);
    }

    public function testMembersBecomeUsersEvenWithoutAnAddress(): void
    {
        $report = $this->runner()->run(new MybbUserMigration($this->em(), [], $this->database()), false);

        self::assertSame(2, $report->created());
        self::assertSame(0, $report->skipped());

        /** @var User $user */
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@mybb.test']);
        self::assertSame('admin', $user->getUsername());
        self::assertStringContainsString('<em>imza</em>', (string) ($user->getData()['signature'] ?? ''));

        /** @var User $nomail */
        $nomail = $this->em()->getRepository(User::class)->findOneBy(['username' => 'nomail']);
        self::assertSame('imported-mybb-2@invalid.invalid', $nomail->getEmail());
    }

    /**
     * MyBB keeps categories and forums in one table and tells them apart with a
     * single character; a link is an ordinary forum that happens to have a
     * target, so the target has to win over the type.
     */
    public function testCategoriesForumsAndLinksAreToldApart(): void
    {
        $this->importAll();
        $this->em()->clear();

        $byCode = [];
        foreach ($this->em()->getRepository(ForumSection::class)->findBy([]) as $section) {
            $byCode[$section->getCode()] = $section;
        }

        self::assertSame(ForumNodeType::Category, $byCode['mybb-1']->getNodeType());
        self::assertSame(ForumNodeType::Forum, $byCode['mybb-2']->getNodeType());
        self::assertSame(ForumNodeType::Link, $byCode['mybb-3']->getNodeType());
        self::assertSame('https://example.test', $byCode['mybb-3']->getLinkUrl());
        self::assertSame('mybb-1', $byCode['mybb-2']->getParent()?->getCode());
    }

    public function testTopicsKeepTheirOriginalDates(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var ForumTopic $topic */
        $topic = $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'Açık konu']);

        // 1500100000 as a Unix timestamp, read as UTC.
        self::assertSame('2017-07-15', $topic->getCreatedAt()->format('Y-m-d'));
        self::assertSame(7, $topic->getViewCount());
    }

    /**
     * MyBB writes "moved|tid" into closed for the redirect a moved thread
     * leaves behind, so comparing that column to "1" would read it as open.
     */
    public function testAMovedThreadIsLockedRatherThanOpen(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var ForumTopic $open */
        $open = $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'Açık konu']);
        /** @var ForumTopic $moved */
        $moved = $this->em()->getRepository(ForumTopic::class)->findOneBy(['title' => 'Taşınmış konu']);

        self::assertFalse($open->isLocked());
        self::assertTrue($moved->isLocked());
    }

    public function testThreadsInAForumThatDoesNotExistAreSkippedNotFailed(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $db = $this->database();

        $runner->run(new MybbUserMigration($em, [], $db), false);
        $runner->run(new MybbSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false);
        $report = $runner->run(new MybbTopicMigration($em, $this->lookup(), [], $db), false);

        self::assertSame(2, $report->created());
        self::assertSame(1, $report->skipped());
        self::assertSame(0, $report->failed());
    }

    /**
     * The three-state visible column is carried across rather than collapsed:
     * a board's moderation queue is part of its history, and publishing what
     * was held is the one outcome nobody can spot afterwards.
     */
    public function testTheThreeVisibilityStatesAllSurvive(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<ForumPost> $posts */
        $posts = $this->em()->getRepository(ForumPost::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(3, $posts);
        self::assertSame(ForumDiscussionState::Visible, $posts[0]->getDiscussionState());
        self::assertSame(ForumDiscussionState::Moderated, $posts[1]->getDiscussionState());
        self::assertSame(ForumDiscussionState::Deleted, $posts[2]->getDiscussionState());
    }

    public function testPostBodiesAreConvertedFromBbcode(): void
    {
        $this->importAll();
        $this->em()->clear();

        /** @var list<ForumPost> $posts */
        $posts = $this->em()->getRepository(ForumPost::class)->findBy([], ['id' => 'ASC']);

        self::assertStringContainsString('<strong>kalın</strong>', $posts[0]->getBody());
        self::assertStringContainsString('href="https://example.test"', $posts[0]->getBody());
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
            $runner->run(new MybbUserMigration($em, [], $db), false),
            $runner->run(new MybbSectionMigration($em, $this->lookup(), ['locale' => 'tr'], $db), false),
            $runner->run(new MybbTopicMigration($em, $this->lookup(), [], $db), false),
            $runner->run(new MybbPostMigration($em, $this->lookup(), [], $db), false),
        ];

        foreach ($reports as $report) {
            self::assertSame(0, $report->created(), $report->migrationId.' created rows on a second run');
            self::assertFalse($report->hasFailures(), $report->migrationId.' failed on a second run');
        }

        $this->em()->clear();
        self::assertCount($before, $this->em()->getRepository(ForumPost::class)->findBy([]));
    }
}
