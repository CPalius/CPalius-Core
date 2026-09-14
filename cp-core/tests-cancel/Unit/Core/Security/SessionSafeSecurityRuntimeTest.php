<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Twig\SessionSafeSecurityRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(SessionSafeSecurityRuntime::class)]
final class SessionSafeSecurityRuntimeTest extends TestCase
{
    public function testAnonymousRequestDoesNotTouchSecurity(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('getUser');
        $security->expects(self::never())->method('isGranted');

        $stack = new RequestStack();
        $stack->push(Request::create('/tr'));

        $runtime = new SessionSafeSecurityRuntime($stack, $security);

        self::assertNull($runtime->user());
        self::assertFalse($runtime->isGranted('forum.view'));
        self::assertSame([], $runtime->flashes());
    }

    public function testExistingSessionAllowsSecurityLookups(): void
    {
        $user = new InMemoryUser('ali', null, ['ROLE_USER']);
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('getUser')->willReturn($user);
        $security->expects(self::once())->method('isGranted')->with('forum.view', null)->willReturn(true);

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->getFlashBag()->add('success', 'ok');

        $request = Request::create('/tr');
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());

        $stack = new RequestStack();
        $stack->push($request);

        $runtime = new SessionSafeSecurityRuntime($stack, $security);

        self::assertSame($user, $runtime->user());
        self::assertTrue($runtime->isGranted('forum.view'));
        self::assertSame(['success' => ['ok']], $runtime->flashes());
    }
}
