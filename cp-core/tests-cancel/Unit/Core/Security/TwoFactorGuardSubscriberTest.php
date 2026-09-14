<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\CapabilityRegistry;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\RoleConfigManager;
use App\Core\Security\SecretBox;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Security\TwoFactor\TotpGenerator;
use App\Core\Security\TwoFactor\TwoFactorGuardSubscriber;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Security\TwoFactor\TwoFactorSession;
use App\Entity\User;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityClock;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(TwoFactorGuardSubscriber::class)]
#[CoversClass(TwoFactorSession::class)]
final class TwoFactorGuardSubscriberTest extends TestCase
{
    private const APP_SECRET = 'kernel-secret-for-tests';

    private TotpGenerator $totp;
    private SecretBox $secretBox;
    private ArraySettings $settings;
    private TwoFactorService $service;
    private TwoFactorSession $twoFactorSession;
    private Session $session;
    private User $user;

    public static function setUpBeforeClass(): void
    {
        SecurityClock::install();
    }

    protected function setUp(): void
    {
        $this->totp = new TotpGenerator();
        $this->secretBox = new SecretBox(self::APP_SECRET);
        $this->settings = new ArraySettings([
            'security.twofactor_enabled' => true,
            'security.flood_enabled' => true,
        ]);

        $cache = new ArrayAdapter(storeSerialized: false);
        $this->service = new TwoFactorService(
            $this->totp,
            $this->secretBox,
            $this->settings,
            new RoleConfigManager(new CapabilityRegistry(), sys_get_temp_dir().'/cp-no-roles', $cache),
            $this->createMock(EntityManagerInterface::class),
            new FloodService($cache, $this->settings),
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
            self::APP_SECRET,
        );

        $this->twoFactorSession = new TwoFactorSession();
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
        $this->user = SecurityTestDatabase::userWithId(42);
    }

    private function enroll(): void
    {
        $secret = $this->service->beginEnrollment($this->user);
        self::assertNotNull($this->service->confirmEnrollment($this->user, $this->totp->currentCode($secret)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function handle(string $path = '/aacp/dashboard', ?User $user = null, array $headers = []): RequestEvent
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $subscriber = new TwoFactorGuardSubscriber($security, $this->service, $this->twoFactorSession);

        $request = Request::create($path);
        $request->setSession($this->session);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        $subscriber->onKernelRequest($event);

        return $event;
    }

    public function testAnAnonymousRequestIsLeftAlone(): void
    {
        self::assertNull($this->handle('/tr/blog')->getResponse());
    }

    public function testAnAccountWithoutTwoFactorPassesThrough(): void
    {
        self::assertNull($this->handle(user: $this->user)->getResponse());
    }

    public function testAnEnrolledButUnverifiedSessionIsHeldAtTheChallenge(): void
    {
        $this->enroll();

        $response = $this->handle(user: $this->user)->getResponse();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(TwoFactorGuardSubscriber::CHALLENGE_PATH, $response->getTargetUrl());
    }

    public function testAVerifiedSessionPassesThrough(): void
    {
        $this->enroll();
        $this->twoFactorSession->markVerified($this->session);

        self::assertNull($this->handle(user: $this->user)->getResponse());
    }

    public function testClearingTheMarkerPutsTheSessionBackAtTheChallenge(): void
    {
        $this->enroll();
        $this->twoFactorSession->markVerified($this->session);
        $this->twoFactorSession->clear($this->session);

        self::assertNotNull($this->handle(user: $this->user)->getResponse());
    }

    public function testTheGlobalSwitchBypassesTheGuardEntirely(): void
    {
        $this->enroll();
        $this->settings->put('security.twofactor_enabled', false);

        self::assertNull($this->handle(user: $this->user)->getResponse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function alwaysReachablePaths(): iterable
    {
        yield 'the challenge itself' => [TwoFactorGuardSubscriber::CHALLENGE_PATH];
        yield 'the setup screen' => [TwoFactorGuardSubscriber::SETUP_PATH];
        yield 'logout' => ['/logout'];
        yield 'the Turkish logout' => ['/hesap/cikis'];
        yield 'the recovery console' => ['/aacp/recovery'];
        yield 'assets' => ['/assets/app.css'];
    }

    /**
     * If the challenge itself were gated, the challenge would be unreachable and
     * the account permanently locked out.
     */
    #[DataProvider('alwaysReachablePaths')]
    public function testTheEscapeHatchesStayReachable(string $path): void
    {
        $this->enroll();

        self::assertNull($this->handle($path, $this->user)->getResponse());
    }

    public function testAFetchCallerGetsAStatusCodeRatherThanARedirect(): void
    {
        $this->enroll();

        $response = $this->handle(user: $this->user, headers: ['Accept' => 'application/json'])->getResponse();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('two_factor_required', (string) $response->getContent());
    }

    public function testAnXmlHttpRequestGetsAStatusCodeToo(): void
    {
        $this->enroll();

        $response = $this->handle(user: $this->user, headers: ['X-Requested-With' => 'XMLHttpRequest'])->getResponse();

        self::assertInstanceOf(JsonResponse::class, $response);
    }

    /**
     * Regression: an account whose sealed secret no longer opens used to read as
     * "never enrolled", so the guard fell straight through and the second factor
     * of every affected account silently switched itself off.
     */
    public function testABrokenSecretSendsTheAccountToSetupInsteadOfLettingItThrough(): void
    {
        $this->enroll();
        $this->user->setDataValue('two_factor_secret', 'v2:'.base64_encode(random_bytes(40)));

        $response = $this->handle(user: $this->user)->getResponse();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(TwoFactorGuardSubscriber::SETUP_PATH, $response->getTargetUrl());
    }

    public function testABrokenSecretIsGatedEvenWhenEnforcementIsOff(): void
    {
        // The enforcement setting decides who *must* enrol; it must not decide
        // whether a factor that already exists may quietly stop working.
        $this->settings->put('security.twofactor_enforce_privileged', false);
        $this->enroll();
        $this->user->setDataValue('two_factor_secret', 'v2:'.base64_encode(random_bytes(40)));

        self::assertNotNull($this->handle(user: $this->user)->getResponse());
    }

    public function testABrokenSecretIsNotGatedOnTheSetupScreenItself(): void
    {
        $this->enroll();
        $this->user->setDataValue('two_factor_secret', 'v2:'.base64_encode(random_bytes(40)));

        self::assertNull($this->handle(TwoFactorGuardSubscriber::SETUP_PATH, $this->user)->getResponse());
    }

    public function testARequestWithoutASessionIsLeftAlone(): void
    {
        $this->enroll();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $subscriber = new TwoFactorGuardSubscriber($security, $this->service, $this->twoFactorSession);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/aacp/dashboard'),
            HttpKernelInterface::MAIN_REQUEST,
        );
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $this->enroll();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $subscriber = new TwoFactorGuardSubscriber($security, $this->service, $this->twoFactorSession);

        $request = Request::create('/aacp/dashboard');
        $request->setSession($this->session);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testItRunsAfterTheFirewallHasResolvedTheToken(): void
    {
        // The firewall listener sits at 8; this must see the token it produced.
        self::assertSame(['onKernelRequest', 6], TwoFactorGuardSubscriber::getSubscribedEvents()['kernel.request']);
    }

    public function testThePendingSecretSurvivesAReloadAndIsDroppedOnVerification(): void
    {
        self::assertNull($this->twoFactorSession->pendingSecret($this->session));

        $this->twoFactorSession->setPendingSecret($this->session, 'GEZDGNBVGY3TQOJQ');
        self::assertSame('GEZDGNBVGY3TQOJQ', $this->twoFactorSession->pendingSecret($this->session));

        $this->twoFactorSession->markVerified($this->session);
        self::assertNull($this->twoFactorSession->pendingSecret($this->session));
    }
}
