<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Flood\FloodService;
use App\Core\Security\Reauth\RecentAuth;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Settings\SettingsRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecentAuthTest extends TestCase
{
    private function recentAuth(int $minutes): RecentAuth
    {
        $settings = $this->createMock(SettingsRegistry::class);
        $settings->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === 'security.reauth_minutes' ? $minutes : $default);

        return new RecentAuth(
            $settings,
            $this->createMock(UserPasswordHasherInterface::class),
            (new \ReflectionClass(TwoFactorService::class))->newInstanceWithoutConstructor(),
            new FloodService(new ArrayAdapter(), $settings),
        );
    }

    public function testASessionThatNeverConfirmedIsNotFresh(): void
    {
        self::assertFalse($this->recentAuth(10)->isFresh(new Session(new MockArraySessionStorage())));
    }

    public function testMarkingMakesItFreshAndItAgesOut(): void
    {
        $auth = $this->recentAuth(10);
        $session = new Session(new MockArraySessionStorage());

        $auth->mark($session);
        self::assertTrue($auth->isFresh($session));

        $session->set(RecentAuth::SESSION_KEY, time() - 601);
        self::assertFalse($auth->isFresh($session), 'ten minutes and a second later it has expired');

        $auth->forget($session);
        self::assertFalse($auth->isFresh($session));
    }

    public function testATimestampFromTheFutureIsNotTrusted(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(RecentAuth::SESSION_KEY, time() + 3600);

        self::assertFalse($this->recentAuth(10)->isFresh($session));
    }

    public function testZeroMinutesTurnsTheRequirementOff(): void
    {
        $auth = $this->recentAuth(0);

        self::assertFalse($auth->enabled());
        self::assertTrue($auth->isFresh(new Session(new MockArraySessionStorage())));
    }
}
