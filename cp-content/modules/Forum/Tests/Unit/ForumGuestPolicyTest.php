<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Service\ForumGuestPolicy;
use Modules\Forum\Service\ForumVisitorKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForumGuestPolicy::class)]
final class ForumGuestPolicyTest extends TestCase
{
    #[DataProvider('hideFromVisitorCases')]
    public function testHideFromVisitor(bool $settingOn, string $kind, bool $expected): void
    {
        self::assertSame($expected, ForumGuestPolicy::hideFromVisitor($settingOn, $kind));
    }

    /**
     * @return iterable<string, array{bool, string, bool}>
     */
    public static function hideFromVisitorCases(): iterable
    {
        yield 'off_guest' => [false, ForumVisitorKind::GUEST, false];
        yield 'off_bot' => [false, ForumVisitorKind::BOT, false];
        yield 'on_guest' => [true, ForumVisitorKind::GUEST, true];
        yield 'on_bot' => [true, ForumVisitorKind::BOT, true];
        yield 'on_member' => [true, ForumVisitorKind::MEMBER, false];
        yield 'on_spider' => [true, ForumVisitorKind::SPIDER, false];
    }

    public function testAdminAlwaysSeesSpoilers(): void
    {
        self::assertTrue(ForumGuestPolicy::spoilerUnlocked(
            true,
            ForumVisitorKind::MEMBER,
            false,
            false,
            false,
        ));
    }

    public function testAdminSeesSpoilersEvenIfClassifiedAsGuest(): void
    {
        self::assertTrue(ForumGuestPolicy::spoilerUnlocked(
            true,
            ForumVisitorKind::GUEST,
            false,
            false,
            false,
        ));
    }

    public function testSpiderSeesSpoilersForIndexing(): void
    {
        self::assertTrue(ForumGuestPolicy::spoilerUnlocked(
            false,
            ForumVisitorKind::SPIDER,
            false,
            false,
            false,
        ));
    }

    public function testGuestNeverUnlocks(): void
    {
        self::assertFalse(ForumGuestPolicy::spoilerUnlocked(
            false,
            ForumVisitorKind::GUEST,
            false,
            true,
            true,
        ));
    }

    public function testMemberUnlocksByLikeOrReplyOrAuthor(): void
    {
        self::assertTrue(ForumGuestPolicy::spoilerUnlocked(false, ForumVisitorKind::MEMBER, true, false, false));
        self::assertTrue(ForumGuestPolicy::spoilerUnlocked(false, ForumVisitorKind::MEMBER, false, true, false));
        self::assertTrue(ForumGuestPolicy::spoilerUnlocked(false, ForumVisitorKind::MEMBER, false, false, true));
        self::assertFalse(ForumGuestPolicy::spoilerUnlocked(false, ForumVisitorKind::MEMBER, false, false, false));
    }
}
