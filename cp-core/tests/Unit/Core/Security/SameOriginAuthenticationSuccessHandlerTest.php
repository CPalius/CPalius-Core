<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Http\SameOriginAuthenticationSuccessHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Http\HttpUtils;

/**
 * Off-origin _target_path and session target_path must not redirect after login.
 */
#[CoversClass(SameOriginAuthenticationSuccessHandler::class)]
final class SameOriginAuthenticationSuccessHandlerTest extends TestCase
{
    private const ORIGIN = 'https://site.example';
    private const DEFAULT_PATH = '/admin';

    /**
     * @return iterable<string, array{string}>
     */
    public static function offOriginTargets(): iterable
    {
        yield 'plain absolute' => ['https://evil.example/phish'];
        yield 'http downgrade' => ['http://evil.example/'];
        yield 'suffix-extended host' => ['https://site.example.evil/phish'];
        yield 'protocol relative' => ['//evil.example/phish'];
        yield 'backslash variant' => ['\\\\evil.example/phish'];
    }

    #[DataProvider('offOriginTargets')]
    public function testOffOriginTargetPathFallsBackToTheDefault(string $target): void
    {
        $response = $this->authenticate(targetPathParameter: $target);

        self::assertSame(self::ORIGIN.self::DEFAULT_PATH, $response->getTargetUrl());
    }

    #[DataProvider('offOriginTargets')]
    public function testOffOriginSessionTargetFallsBackToTheDefault(string $target): void
    {
        $response = $this->authenticate(sessionTarget: $target);

        self::assertSame(self::ORIGIN.self::DEFAULT_PATH, $response->getTargetUrl());
    }

    /**
     * The guard must not break the feature it protects: returning where the user
     * came from is the entire point of a target path.
     */
    public function testRelativeTargetIsKept(): void
    {
        $response = $this->authenticate(sessionTarget: '/forums/thread/12-hello');

        self::assertSame(self::ORIGIN.'/forums/thread/12-hello', $response->getTargetUrl());
    }

    public function testAbsoluteSameOriginTargetIsKept(): void
    {
        $response = $this->authenticate(sessionTarget: self::ORIGIN.'/hesap/profil');

        self::assertSame(self::ORIGIN.'/hesap/profil', $response->getTargetUrl());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonNavigableTargets(): iterable
    {
        yield 'live-feed poll' => ['/aacp/telemetry/live-feed?after_id=6301'];
        yield 'absolute live-feed' => [self::ORIGIN.'/aacp/telemetry/live-feed?after_id=6301'];
        yield 'inbox pulse' => ['/hesap/nabiz'];
        yield 'system metrics' => ['/aacp/system/metrics'];
        yield 'login door' => ['/login?session_expired=idle_timeout'];
    }

    #[DataProvider('nonNavigableTargets')]
    public function testJsonOrAuthDoorTargetFallsBackToTheDefault(string $target): void
    {
        $response = $this->authenticate(sessionTarget: $target);

        self::assertSame(self::ORIGIN.self::DEFAULT_PATH, $response->getTargetUrl());
    }

    private function authenticate(?string $targetPathParameter = null, ?string $sessionTarget = null): RedirectResponse
    {
        $request = Request::create(self::ORIGIN.'/login', 'POST', $targetPathParameter !== null ? ['_target_path' => $targetPathParameter] : []);

        $session = new Session(new MockArraySessionStorage());
        if ($sessionTarget !== null) {
            $session->set('_security.main.target_path', $sessionTarget);
        }
        $request->setSession($session);

        $handler = new SameOriginAuthenticationSuccessHandler($this->httpUtils());
        $handler->setOptions(['default_target_path' => self::DEFAULT_PATH, 'login_path' => '/login']);
        $handler->setFirewallName('main');

        $response = $handler->onAuthenticationSuccess($request, new NullToken());

        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }

    private function httpUtils(): HttpUtils
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        return new HttpUtils($urlGenerator);
    }
}
