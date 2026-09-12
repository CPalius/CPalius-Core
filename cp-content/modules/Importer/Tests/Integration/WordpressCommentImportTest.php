<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Integration;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationRunner;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use App\Tests\Support\IntegrationTestCase;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Migrate\BlogCommentDestination;
use Modules\Importer\Migration\Wordpress\WordpressAuthorMigration;
use Modules\Importer\Migration\Wordpress\WordpressCategoryMigration;
use Modules\Importer\Migration\Wordpress\WordpressCommentMigration;
use Modules\Importer\Migration\Wordpress\WordpressPostMigration;
use Modules\Importer\Migration\Wordpress\WordpressTagMigration;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WordpressCommentMigration::class)]
#[CoversClass(BlogCommentDestination::class)]
#[CoversClass(\Modules\Importer\Source\Wordpress\WxrCommentSource::class)]
final class WordpressCommentImportTest extends IntegrationTestCase
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

    private function comments(): WordpressCommentMigration
    {
        return (new WordpressCommentMigration($this->em(), $this->lookup()))
            ->withOptions(['file' => self::FIXTURE]);
    }

    /**
     * Posts and their prerequisites, but no attachments — comments do not need
     * them and the uploads directory is a separate concern.
     */
    private function importPosts(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $options = ['file' => self::FIXTURE, 'locale' => 'tr'];
        /** @var NodeRepository $nodes */
        $nodes = $em->getRepository(Node::class);

        $runner->run((new WordpressAuthorMigration($em))->withOptions(['file' => self::FIXTURE]), false);
        $runner->run((new WordpressCategoryMigration($em, $this->lookup()))->withOptions($options), false);
        $runner->run((new WordpressTagMigration($em))->withOptions($options), false);
        $runner->run((new WordpressPostMigration($em, new SlugGenerator($nodes), $this->lookup()))->withOptions($options), false);
    }

    public function testACommentLandsOnThePostItBelongedTo(): void
    {
        $this->importPosts();

        $report = $this->runner()->run($this->comments(), false);

        self::assertSame(1, $report->created());
        self::assertFalse($report->hasFailures());

        $this->em()->clear();
        /** @var list<BlogComment> $comments */
        $comments = $this->em()->getRepository(BlogComment::class)->findBy([]);
        self::assertCount(1, $comments);
        self::assertSame('Güzel yazı', $comments[0]->getBody());
        self::assertSame(BlogComment::STATUS_APPROVED, $comments[0]->getStatus());

        // Resolved through the map, not by matching titles.
        self::assertSame('İlk Yazı', $comments[0]->getNode()->getTitle());
    }

    /**
     * The commenter's address does not belong to any imported account, so the
     * comment stays a guest comment rather than being attributed to somebody.
     */
    public function testACommenterWhoIsNotAUserStaysAGuest(): void
    {
        $this->importPosts();
        $this->runner()->run($this->comments(), false);

        $this->em()->clear();
        /** @var BlogComment $comment */
        $comment = $this->em()->getRepository(BlogComment::class)->findBy([])[0];

        self::assertNull($comment->getAuthor());
        self::assertSame('Okur', $comment->getGuestName());
        self::assertSame('okur@example.com', $comment->getGuestEmail());
    }

    public function testACommentWhoseAddressMatchesAnAccountIsAttachedToIt(): void
    {
        $this->importPosts();

        // The address the fixture's comment uses, now belonging to a real user.
        $user = new User('okur@example.com');
        $user->setPassword('x')->setStatus(User::STATUS_ACTIVE);
        $this->em()->persist($user);
        $this->em()->flush();

        $this->runner()->run($this->comments(), false);

        $this->em()->clear();
        /** @var BlogComment $comment */
        $comment = $this->em()->getRepository(BlogComment::class)->findBy([])[0];

        self::assertSame('okur@example.com', $comment->getAuthor()?->getEmail());
        self::assertNull($comment->getGuestEmail());
    }

    /**
     * Not an error: the posts migration deliberately leaves out pages and
     * auto-drafts, so some comments have nowhere to go. Failing each one would
     * bury the real problems.
     */
    public function testCommentsOnPostsThatWereNotImportedAreSkippedNotFailed(): void
    {
        // No posts imported at all, so every comment is an orphan.
        $report = $this->runner()->run($this->comments(), false);

        self::assertSame(0, $report->failed());
        self::assertSame(1, $report->skipped());
        self::assertCount(0, $this->em()->getRepository(BlogComment::class)->findBy([]));
    }

    public function testReimportingCommentsCreatesNothingNew(): void
    {
        $this->importPosts();
        $runner = $this->runner();

        $runner->run($this->comments(), false);
        $report = $runner->run($this->comments(), false);

        self::assertSame(0, $report->created());
        self::assertSame(1, $report->unchanged());
        self::assertCount(1, $this->em()->getRepository(BlogComment::class)->findBy([]));
    }

    public function testRollingBackCommentsLeavesThePostsAlone(): void
    {
        $this->importPosts();
        $runner = $this->runner();
        $runner->run($this->comments(), false);

        $report = $runner->rollback($this->comments(), false);

        self::assertFalse($report->hasFailures());
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(BlogComment::class)->findBy([]));
        self::assertCount(2, $this->em()->getRepository(Node::class)->findBy([]));
    }

    /**
     * Poster IPs are personal data describing where somebody was years ago on
     * a site that no longer exists, and nothing here acts on them.
     */
    public function testPosterIpAddressesAreNotCarriedOver(): void
    {
        $this->importPosts();
        $this->runner()->run($this->comments(), false);

        $this->em()->clear();
        /** @var BlogComment $comment */
        $comment = $this->em()->getRepository(BlogComment::class)->findBy([])[0];

        self::assertNull($comment->getPosterIp());
    }
}
