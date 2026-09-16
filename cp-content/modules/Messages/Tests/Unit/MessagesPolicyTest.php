<?php

declare(strict_types=1);

namespace Modules\Messages\Tests\Unit;

use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Service\MessagesContext;
use Modules\Messages\Service\MessagesQuotaSnapshot;
use Modules\Messages\Service\MessagesUserLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(MessageThread::class)]
#[CoversClass(MessagesContext::class)]
#[CoversClass(MessagesQuotaSnapshot::class)]
#[CoversClass(MessagesUserLookup::class)]
final class MessagesPolicyTest extends TestCase
{
    public function testPairKeyIsStableRegardlessOfArgumentOrder(): void
    {
        self::assertSame(
            MessageThread::pairKey(2, 9, 'showcase_item', 12),
            MessageThread::pairKey(9, 2, 'showcase_item', 12),
        );
        self::assertNotSame(
            MessageThread::pairKey(2, 9, 'showcase_item', 12),
            MessageThread::pairKey(2, 9, 'forum_user', 12),
        );
        self::assertSame('2:9:-:-', MessageThread::pairKey(9, 2, null, null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function handles(): iterable
    {
        yield 'plain' => ['ali', 'ali'];
        yield 'at' => ['@ali', 'ali'];
        yield 'at space' => ['@ ali', 'ali'];
        yield 'spaces' => ['  ali  ', 'ali'];
    }

    #[DataProvider('handles')]
    public function testNormalizeHandleStripsAtAndSpace(string $raw, string $expected): void
    {
        self::assertSame($expected, MessagesUserLookup::normalizeHandle($raw));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'relative' => ['/tr/showcase/car', '/tr/showcase/car'];
        yield 'https' => ['https://example.com/a', 'https://example.com/a'];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'protocol-relative' => ['//evil.test', null];
        yield 'newline smuggle' => ["java\nscript:alert(1)", null];
    }

    #[DataProvider('urls')]
    public function testContextUrlAllowlist(string $raw, ?string $expected): void
    {
        self::assertSame($expected, MessagesContext::sanitizeUrl($raw));
    }

    public function testContextIgnoresUnknownTypeTokens(): void
    {
        $request = new Request(query: [
            'context_type' => 'Not A Type!',
            'context_id' => '12',
            'context_url' => 'https://ok.example/item',
        ]);
        $context = MessagesContext::fromRequest($request);

        self::assertNull($context->type);
        self::assertSame(12, $context->id);
        self::assertSame('https://ok.example/item', $context->url);
    }

    public function testQuotaTreatsZeroLimitAsUnlimited(): void
    {
        $snapshot = new MessagesQuotaSnapshot(50, 0, 200, 0, 9, 0, false);

        self::assertTrue($snapshot->canSend());
        self::assertTrue($snapshot->canStartThread());
        self::assertNull($snapshot->dailyRemaining());
    }

    public function testQuotaBlocksWhenDailyCapIsHit(): void
    {
        $snapshot = new MessagesQuotaSnapshot(1, 20, 50, 50, 0, 10, false);

        self::assertFalse($snapshot->canSend());
        self::assertFalse($snapshot->canStartThread());
        self::assertSame(0, $snapshot->dailyRemaining());
    }

    public function testExemptIgnoresCaps(): void
    {
        $snapshot = new MessagesQuotaSnapshot(99, 1, 99, 1, 99, 1, true);

        self::assertTrue($snapshot->canSend());
        self::assertTrue($snapshot->canStartThread());
        self::assertNull($snapshot->hourlyRemaining());
    }
}
