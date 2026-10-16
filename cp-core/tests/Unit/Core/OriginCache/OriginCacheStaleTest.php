<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\OriginCacheStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginCacheStore::class)]
final class OriginCacheStaleTest extends TestCase
{
    private string $projectDir;

    private OriginCacheStore $store;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-stale-'.bin2hex(random_bytes(6));
        $this->store = new OriginCacheStore($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->store->purgeAll();
    }

    public function testAPurgeSetsThePageAsideInsteadOfDestroyingIt(): void
    {
        $this->store->putHtml('/blog/post-42', '', '<html>old</html>');

        self::assertSame(1, $this->store->deleteExact('/blog/post-42'));

        self::assertNull($this->store->getHtml('/blog/post-42', '', 0), 'Apache and the reader no longer see a fresh page');
        self::assertSame('<html>old</html>', $this->store->getStaleHtml('/blog/post-42', ''), 'but the previous copy is kept for the requests that arrive during regeneration');
    }

    public function testOnlyOneRequestGetsTheRegenerationLock(): void
    {
        $this->store->putHtml('/blog/post-42', '', '<html>old</html>');
        $this->store->deleteExact('/blog/post-42');

        $first = $this->store->tryRegenerationLock('/blog/post-42', '');
        self::assertNotNull($first);
        self::assertNull($this->store->tryRegenerationLock('/blog/post-42', ''), 'the second request is told someone is already rebuilding it');

        $this->store->putHtml('/blog/post-42', '', '<html>new</html>');
        $this->store->finishRegeneration($first, '/blog/post-42', '');

        self::assertNull($this->store->getStaleHtml('/blog/post-42', ''), 'a fresh page replaces the stale copy');
        self::assertSame('<html>new</html>', $this->store->getHtml('/blog/post-42', '', 0));
        $again = $this->store->tryRegenerationLock('/blog/post-42', '');
        self::assertNotNull($again, 'the lock is free for the next purge');
        fclose($again);
    }

    public function testAFailedRegenerationRemovesTheStaleCopy(): void
    {
        $this->store->putHtml('/blog/gone', '', '<html>old</html>');
        $this->store->deleteExact('/blog/gone');
        $lock = $this->store->tryRegenerationLock('/blog/gone', '');
        self::assertNotNull($lock);

        // the rebuilt response was a 404: nothing is written, the writer just finishes
        $this->store->finishRegeneration($lock, '/blog/gone', '');

        self::assertNull($this->store->getStaleHtml('/blog/gone', ''), 'a deleted page must not keep being served from the stale copy');
    }

    public function testAStaleCopyExpiresAfterTheGraceWindow(): void
    {
        $this->store->putHtml('/blog/post-42', '', '<html>old</html>');
        $this->store->deleteExact('/blog/post-42');
        $file = $this->store->htmlPath('/blog/post-42', '').'.stale';
        touch($file, time() - OriginCacheStore::STALE_GRACE_SECONDS - 5);

        self::assertNull($this->store->getStaleHtml('/blog/post-42', ''));
    }

    public function testASnapshotGetsAGzipCopyThatDecodesToTheSamePage(): void
    {
        $html = '<html>'.str_repeat('<p>hello</p>', 50).'</html>';
        $this->store->putHtml('/blog/post-42', '', $html);

        $gz = $this->store->htmlPath('/blog/post-42', '').'.gz';
        self::assertFileExists($gz);
        self::assertSame($html, gzdecode((string) file_get_contents($gz)));
        self::assertLessThan(\strlen($html), filesize($gz));
    }

    public function testAPageWithAQueryStringGetsNoGzipCopy(): void
    {
        $this->store->putHtml('/blog', 'page=2', '<html>p2</html>');

        self::assertFileDoesNotExist($this->store->htmlPath('/blog', 'page=2').'.gz');
    }

    public function testAPurgeRemovesTheGzipCopySoApacheCannotServeAPurgedPage(): void
    {
        $this->store->putHtml('/blog/post-42', '', '<html>old</html>');
        $gz = $this->store->htmlPath('/blog/post-42', '').'.gz';
        self::assertFileExists($gz);

        $this->store->deleteExact('/blog/post-42');

        self::assertFileDoesNotExist($gz);
    }
}
