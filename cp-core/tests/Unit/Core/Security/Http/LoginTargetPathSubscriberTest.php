<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Http;

use App\Core\Security\Http\LoginTargetPathSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(LoginTargetPathSubscriber::class)]
final class LoginTargetPathSubscriberTest extends TestCase
{
    public function testDropsALiveFeedTarget(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('_security.main.target_path', '/aacp/telemetry/live-feed?after_id=6301');

        $request = Request::create('/login', 'GET');
        $request->setSession($session);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new LoginTargetPathSubscriber())->onKernelRequest($event);

        self::assertFalse($session->has('_security.main.target_path'));
    }

    public function testKeepsARealPageTarget(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('_security.main.target_path', '/aacp/security');

        $request = Request::create('/login', 'GET');
        $request->setSession($session);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new LoginTargetPathSubscriber())->onKernelRequest($event);

        self::assertSame('/aacp/security', $session->get('_security.main.target_path'));
    }
}
