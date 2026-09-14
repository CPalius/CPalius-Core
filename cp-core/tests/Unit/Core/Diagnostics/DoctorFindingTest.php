<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Diagnostics;

use App\Core\Diagnostics\DoctorFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * isAtLeast() decides whether CI goes red, so its edges are tested rather
 * than assumed.
 */
#[CoversClass(DoctorFinding::class)]
final class DoctorFindingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function thresholdCases(): iterable
    {
        yield 'critical trips a critical gate' => [DoctorFinding::SEVERITY_CRITICAL, 'critical', true];
        yield 'high does not trip a critical gate' => [DoctorFinding::SEVERITY_HIGH, 'critical', false];
        yield 'critical trips a high gate' => [DoctorFinding::SEVERITY_CRITICAL, 'high', true];
        yield 'high trips a high gate' => [DoctorFinding::SEVERITY_HIGH, 'high', true];
        yield 'medium does not trip a high gate' => [DoctorFinding::SEVERITY_MEDIUM, 'high', false];
        yield 'low trips a low gate' => [DoctorFinding::SEVERITY_LOW, 'low', true];
        yield 'medium trips a low gate' => [DoctorFinding::SEVERITY_MEDIUM, 'low', true];

        // A pass is never a failure, at any threshold.
        yield 'pass never trips a low gate' => [DoctorFinding::SEVERITY_PASS, 'low', false];
        yield 'pass never trips a critical gate' => [DoctorFinding::SEVERITY_PASS, 'critical', false];

        // Fail-safe: an unrecognised threshold matches nothing. The command
        // rejects such input up front, so this is the second line of defence.
        yield 'unknown threshold matches nothing' => [DoctorFinding::SEVERITY_CRITICAL, 'catastrophic', false];
        yield 'empty threshold matches nothing' => [DoctorFinding::SEVERITY_CRITICAL, '', false];
    }

    #[DataProvider('thresholdCases')]
    public function testIsAtLeast(string $severity, string $threshold, bool $expected): void
    {
        $finding = new DoctorFinding('id', $severity, 'Title', 'Detail');

        self::assertSame($expected, $finding->isAtLeast($threshold));
    }

    public function testAnUnknownSeveritySortsLastRatherThanFirst(): void
    {
        // A typo in a check must never let its finding outrank a real critical.
        $typo = new DoctorFinding('id', 'crticial', 'Title', 'Detail');
        $critical = new DoctorFinding('id', DoctorFinding::SEVERITY_CRITICAL, 'Title', 'Detail');

        self::assertGreaterThan($critical->rank(), $typo->rank());
        self::assertFalse($typo->isAtLeast('critical'));
    }

    public function testPassFactoryProducesANonFailingFinding(): void
    {
        $finding = DoctorFinding::pass('id', 'Title', 'All good.');

        self::assertTrue($finding->isPass());
        self::assertSame(DoctorFinding::SEVERITY_PASS, $finding->severity);
        self::assertNull($finding->remedy);
    }

    public function testToArrayCarriesEveryField(): void
    {
        $finding = new DoctorFinding('the.id', DoctorFinding::SEVERITY_HIGH, 'Title', 'Detail', 'Remedy');

        self::assertSame([
            'id' => 'the.id',
            'severity' => 'high',
            'title' => 'Title',
            'detail' => 'Detail',
            'remedy' => 'Remedy',
        ], $finding->toArray());
    }
}
