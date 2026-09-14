<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Diagnostics;

use App\Core\Diagnostics\Doctor;
use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The doctor's own contract: it must survive the failures it is sent to find.
 */
#[CoversClass(Doctor::class)]
final class DoctorTest extends TestCase
{
    public function testABrokenCheckBecomesAFindingAndTheRunContinues(): void
    {
        $doctor = new Doctor([
            new ThrowingCheck('broken'),
            new StaticCheck('healthy', [DoctorFinding::pass('ok', 'Fine', 'Nothing to report.')]),
        ]);

        $findings = $doctor->run();

        self::assertCount(2, $findings);

        $crash = $this->byId($findings, 'doctor.check_failed');
        self::assertNotNull($crash, 'The exception should have been converted into a finding.');
        self::assertSame(DoctorFinding::SEVERITY_MEDIUM, $crash->severity);
        self::assertStringContainsString('database on fire', $crash->detail);

        // The decisive assertion: the healthy check still ran.
        self::assertNotNull($this->byId($findings, 'ok'));
    }

    public function testACheckThatCannotEvenNameItselfIsIsolated(): void
    {
        $doctor = new Doctor([new UnnameableCheck(), new StaticCheck('healthy', [])]);

        $findings = $doctor->run();

        self::assertCount(1, $findings);
        self::assertSame('doctor.check_failed', $findings[0]->id);

        // availableKeys() must never throw, even with the same broken check.
        self::assertSame(['healthy'], $doctor->availableKeys());
    }

    public function testFindingsAreSortedBySeverityThenId(): void
    {
        $doctor = new Doctor([new StaticCheck('mixed', [
            DoctorFinding::pass('z-pass', 'Pass', ''),
            new DoctorFinding('b-low', DoctorFinding::SEVERITY_LOW, 'Low', ''),
            new DoctorFinding('a-critical', DoctorFinding::SEVERITY_CRITICAL, 'Critical', ''),
            new DoctorFinding('a-low', DoctorFinding::SEVERITY_LOW, 'Low', ''),
            new DoctorFinding('a-high', DoctorFinding::SEVERITY_HIGH, 'High', ''),
        ])]);

        self::assertSame(
            ['a-critical', 'a-high', 'a-low', 'b-low', 'z-pass'],
            array_map(static fn (DoctorFinding $f): string => $f->id, $doctor->run()),
        );
    }

    public function testOnlyRestrictsTheRunToTheGivenKeys(): void
    {
        $doctor = new Doctor([
            new StaticCheck('migrations', [new DoctorFinding('m', DoctorFinding::SEVERITY_HIGH, 'M', '')]),
            new StaticCheck('modules', [new DoctorFinding('x', DoctorFinding::SEVERITY_HIGH, 'X', '')]),
        ]);

        $findings = $doctor->run(['migrations']);

        self::assertCount(1, $findings);
        self::assertSame('m', $findings[0]->id);
    }

    public function testAnUnknownOnlyKeyRunsNothingRatherThanEverything(): void
    {
        // Fail-safe direction matters: a typo must not silently run the full
        // suite and report "healthy" for checks the operator did not ask for.
        $doctor = new Doctor([new StaticCheck('migrations', [
            new DoctorFinding('m', DoctorFinding::SEVERITY_HIGH, 'M', ''),
        ])]);

        self::assertSame([], $doctor->run(['nope']));
    }

    public function testSummaryAlwaysReportsEverySeverity(): void
    {
        $doctor = new Doctor([new StaticCheck('mixed', [
            new DoctorFinding('a', DoctorFinding::SEVERITY_HIGH, 'A', ''),
            new DoctorFinding('b', DoctorFinding::SEVERITY_HIGH, 'B', ''),
            DoctorFinding::pass('c', 'C', ''),
        ])]);

        self::assertSame(
            ['critical' => 0, 'high' => 2, 'medium' => 0, 'low' => 0, 'pass' => 1],
            $doctor->summary($doctor->run()),
        );
    }

    public function testIsHealthyOnlyWhenEveryFindingIsAPass(): void
    {
        $healthy = new Doctor([new StaticCheck('a', [DoctorFinding::pass('a', 'A', '')])]);
        $unhealthy = new Doctor([new StaticCheck('a', [
            DoctorFinding::pass('a', 'A', ''),
            new DoctorFinding('b', DoctorFinding::SEVERITY_LOW, 'B', ''),
        ])]);

        self::assertTrue($healthy->isHealthy($healthy->run()));
        self::assertFalse($unhealthy->isHealthy($unhealthy->run()));
    }

    /**
     * @param list<DoctorFinding> $findings
     */
    private function byId(array $findings, string $id): ?DoctorFinding
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }
}

final class StaticCheck implements DoctorCheckInterface
{
    /**
     * @param list<DoctorFinding> $findings
     */
    public function __construct(
        private readonly string $key,
        private readonly array $findings,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function run(): array
    {
        return $this->findings;
    }
}

final class ThrowingCheck implements DoctorCheckInterface
{
    public function __construct(private readonly string $key)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function run(): array
    {
        throw new \RuntimeException('database on fire');
    }
}

final class UnnameableCheck implements DoctorCheckInterface
{
    public function key(): string
    {
        throw new \LogicException('misconfigured check');
    }

    public function run(): array
    {
        return [];
    }
}
