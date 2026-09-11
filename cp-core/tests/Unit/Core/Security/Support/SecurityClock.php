<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Support;

use App\Core\Security\Flood\FloodService;
use App\Core\Security\TwoFactor\TotpGenerator;
use Symfony\Bridge\PhpUnit\ClockMock;

/**
 * Installs the Symfony clock mock in the two security namespaces that read the
 * clock directly.
 *
 * Registration has to happen before the first call, not merely before the first
 * *mocked* call: PHP caches the resolution of an unqualified function call at
 * the call site, so once FloodService has executed one real time() the later
 * definition of App\Core\Security\Flood\time() is never consulted again. Running
 * a test class on its own hid this; in a full suite run an earlier class had
 * already warmed the call site and the clock silently stopped moving.
 *
 * Every security test class that constructs a FloodService or a TotpGenerator
 * calls this from setUpBeforeClass(), whether or not it is time-sensitive, so
 * the registration order does not depend on which classes PHPUnit happens to run
 * first. Registering is harmless on its own: the generated functions delegate to
 * the real ones until a "time-sensitive" test actually enables the mock.
 */
final class SecurityClock
{
    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        ClockMock::register(FloodService::class);
        ClockMock::register(TotpGenerator::class);

        self::$installed = true;
    }
}
