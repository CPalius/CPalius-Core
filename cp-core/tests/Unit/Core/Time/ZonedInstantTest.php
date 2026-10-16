<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Time;

use App\Core\Time\ZonedInstant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZonedInstant::class)]
final class ZonedInstantTest extends TestCase
{
    public function testLocalTimeIsStoredAsUtcAndShownLocally(): void
    {
        $departure = ZonedInstant::fromLocal('2026-11-02 09:00', 'Europe/Istanbul'); // UTC+3 all year

        self::assertSame('2026-11-02 06:00:00', $departure->utcString());
        self::assertSame('2026-11-02 09:00', $departure->format());
        self::assertSame('Europe/Istanbul', $departure->timezone);
    }

    public function testSameInstantAcrossZones(): void
    {
        $istanbul = ZonedInstant::fromLocal('2026-07-01T12:00:00', 'Europe/Istanbul');
        $berlin = ZonedInstant::fromLocal('2026-07-01 11:00', 'Europe/Berlin'); // CEST, UTC+2

        self::assertTrue($istanbul->isSameInstant($berlin));
        self::assertSame('11:00', $istanbul->local()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('H:i'));
    }

    public function testFromUtcKeepsTheZoneForDisplay(): void
    {
        $instant = ZonedInstant::fromUtc(new \DateTimeImmutable('2026-11-02 06:00:00', new \DateTimeZone('UTC')), 'Europe/Istanbul');

        self::assertSame('09:00', $instant->format('H:i'));
    }

    public function testATimeSkippedByDaylightSavingIsRefused(): void
    {
        // Berlin clocks jump from 02:00 to 03:00 on 2026-03-29: 02:30 never happens.
        $this->expectException(\InvalidArgumentException::class);

        ZonedInstant::fromLocal('2026-03-29 02:30', 'Europe/Berlin');
    }

    public function testAbbreviationsAndUnknownZonesAreRefused(): void
    {
        foreach (['EST', 'Mars/Olympus', ''] as $zone) {
            try {
                ZonedInstant::fromLocal('2026-11-02 09:00', $zone);
                self::fail('Expected a refusal for "'.$zone.'".');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testUnreadableTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ZonedInstant::fromLocal('tomorrow', 'Europe/Istanbul');
    }
}
