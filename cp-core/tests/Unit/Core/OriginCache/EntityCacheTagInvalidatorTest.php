<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\Entity\Event\EntityPostDeleteEvent;
use App\Core\Entity\Event\EntityPostInsertEvent;
use App\Core\Entity\Event\EntityPostUpdateEvent;
use App\Core\Hook\HookContext;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\CacheTagIndex;
use App\Core\OriginCache\EntityCacheTagInvalidator;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\OriginCache\OriginCacheStore;
use App\Entity\Node;
use App\Repository\LocaleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * T2.3 kanıtı, end-to-end at the unit level: wiring to the real Hook engine is already
 * proven generically by EntityLifecycleHookIntegrationTest (T1.5) — this proves THIS
 * listener turns an entity event into exactly the right snapshot deletions, against
 * real OriginCacheStore/CacheTagIndex collaborators (both are `final`, so real objects
 * stand in for mocks here — same convention as OriginCachePolicyTest).
 */
#[CoversClass(EntityCacheTagInvalidator::class)]
final class EntityCacheTagInvalidatorTest extends TestCase
{
    private string $projectDir;

    private OriginCacheStore $store;

    private CacheTagIndex $tagIndex;

    private EntityCacheTagInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-invalidator-test-'.bin2hex(random_bytes(6));
        $this->store = new OriginCacheStore($this->projectDir);
        $this->tagIndex = new CacheTagIndex(new ArrayAdapter());
        $purger = new OriginCachePurger($this->store, $this->fakeLocaleProvider(), $this->tagIndex);
        $this->invalidator = new EntityCacheTagInvalidator($purger);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    public function testPostUpdatePurgesTheNodesOwnPageAndBothOfItsListingPages(): void
    {
        $node = $this->nodeWithId(42, 'post');

        $this->store->putHtml('/blog/post-42', '', '<html>post 42</html>');
        $this->tagIndex->record(['node:42'], '/blog/post-42');

        $this->store->putHtml('/blog', '', '<html>archive</html>');
        $this->tagIndex->record(['list:node'], '/blog');

        $this->store->putHtml('/blog/kategori/haber', '', '<html>category</html>');
        $this->tagIndex->record(['list:node:post'], '/blog/kategori/haber');

        $this->store->putHtml('/forum', '', '<html>unrelated module</html>');

        $this->invalidator->onEntityChanged(new HookContext(['event' => new EntityPostUpdateEvent($node, 'node')]));

        self::assertNull($this->store->getHtml('/blog/post-42', '', 0), 'the edited node\'s own page must drop');
        self::assertNull($this->store->getHtml('/blog', '', 0), 'the generic node listing must drop');
        self::assertNull($this->store->getHtml('/blog/kategori/haber', '', 0), 'the bundle listing must drop');
        self::assertNotNull($this->store->getHtml('/forum', '', 0), 'an unrelated module page must never be touched');
    }

    public function testPostDeleteStillReadsTheIdOffTheDetachedObject(): void
    {
        $node = $this->nodeWithId(7, 'post');

        $this->store->putHtml('/blog/post-7', '', '<html>post 7</html>');
        $this->tagIndex->record(['node:7'], '/blog/post-7');

        $this->invalidator->onEntityChanged(new HookContext(['event' => new EntityPostDeleteEvent($node, 'node')]));

        self::assertNull($this->store->getHtml('/blog/post-7', '', 0));
    }

    public function testEntityWithoutAnIdYetIsIgnored(): void
    {
        $node = new Node('No id yet', 'no-id-yet', 'post', 'en');

        $this->store->putHtml('/blog', '', '<html>archive</html>');
        $this->tagIndex->record(['list:node'], '/blog');

        $this->invalidator->onEntityChanged(new HookContext(['event' => new EntityPostInsertEvent($node, 'node')]));

        self::assertNotNull($this->store->getHtml('/blog', '', 0), 'no id means no tag to resolve, so nothing purges');
    }

    public function testContextWithoutAnEntityEventDoesNotThrow(): void
    {
        $this->invalidator->onEntityChanged(new HookContext());

        self::assertTrue(true);
    }

    private function nodeWithId(int $id, string $type): Node
    {
        $node = new Node('Title', 'slug-'.$id, $type, 'en');
        $reflection = new \ReflectionProperty(Node::class, 'id');
        $reflection->setValue($node, $id);

        return $node;
    }

    private function fakeLocaleProvider(): LocaleProvider
    {
        return new LocaleProvider(
            $this->createMock(LocaleRepository::class),
            new ArrayAdapter(),
            'en',
            'en',
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}
