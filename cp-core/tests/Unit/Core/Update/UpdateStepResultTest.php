<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Update;

use App\Core\Update\UpdateStepResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The three outcomes must stay distinguishable: "did work", "had nothing to
 * do", and "failed". Collapsing skipped into applied would make cp:update
 * report changes on an installation it did not touch.
 */
#[CoversClass(UpdateStepResult::class)]
final class UpdateStepResultTest extends TestCase
{
    public function testAppliedReportsAChange(): void
    {
        $result = UpdateStepResult::applied('migrations', '2 applied.', ['V1', 'V2']);

        self::assertTrue($result->changedAnything());
        self::assertFalse($result->isFailure());
        self::assertSame(['V1', 'V2'], $result->details);
    }

    public function testSkippedIsNotAChange(): void
    {
        $result = UpdateStepResult::skipped('migrations', 'Schema is up to date.');

        self::assertFalse($result->changedAnything(), '"nothing to do" must not read as "did something"');
        self::assertFalse($result->isFailure());
        self::assertSame([], $result->details);
    }

    public function testFailedIsNeitherAChangeNorSilent(): void
    {
        $result = UpdateStepResult::failed('config', 'Could not import.', ['field.page — bad type']);

        self::assertTrue($result->isFailure());
        self::assertFalse($result->changedAnything());
        self::assertSame(['field.page — bad type'], $result->details);
    }

    public function testToArrayCarriesEveryField(): void
    {
        self::assertSame([
            'step' => 'cache',
            'status' => 'applied',
            'summary' => 'Cache cleared.',
            'details' => [],
        ], UpdateStepResult::applied('cache', 'Cache cleared.')->toArray());
    }
}
