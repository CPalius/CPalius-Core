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
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Entity\User;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use App\Tests\Unit\Core\Security\Support\SecurityClock;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(TwoFactorService::class)]
#[Group('time-sensitive')]
final class TwoFactorServiceTest extends TestCase
{
    private const APP_SECRET = 'kernel-secret-for-tests';

    private TotpGenerator $totp;
    private SecretBox $secretBox;
    private ArraySettings $settings;
    private FloodService $flood;
    private TwoFactorService $service;
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
        $this->flood = new FloodService($cache, $this->settings);

        $this->service = new TwoFactorService(
            $this->totp,
            $this->secretBox,
            $this->settings,
            new RoleConfigManager(new CapabilityRegistry(), sys_get_temp_dir().'/cp-no-roles', $cache),
            $this->createMock(EntityManagerInterface::class),
            $this->flood,
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
            self::APP_SECRET,
        );

        $this->user = SecurityTestDatabase::userWithId(42);
    }

    private function code(?int $timestamp = null): string
    {
        $secret = $this->user->getDataValue('two_factor_secret');
        self::assertIsString($secret);

        return $this->totp->currentCode($this->secretBox->open($secret), $timestamp);
    }

    /**
     * @return list<string> The recovery codes.
     */
    private function enroll(): array
    {
        $this->service->beginEnrollment($this->user);
        $codes = $this->service->confirmEnrollment($this->user, $this->code());
        self::assertIsArray($codes);

        return $codes;
    }

    // --- enrollment ---------------------------------------------------------

    public function testANewAccountIsNotEnrolled(): void
    {
        self::assertFalse($this->service->isEnrolled($this->user));
        self::assertFalse($this->service->isEnrollmentBroken($this->user));
        self::assertNull($this->service->confirmedAt($this->user));
        self::assertSame(0, $this->service->recoveryCodesRemaining($this->user));
    }

    public function testBeginEnrollmentIssuesASealedButUnconfirmedSecret(): void
    {
        $secret = $this->service->beginEnrollment($this->user);

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $stored = $this->user->getDataValue('two_factor_secret');
        self::assertIsString($stored);
        self::assertNotSame($secret, $stored, 'The secret is sealed, not stored in the clear.');
        self::assertStringNotContainsString($secret, $stored);
        self::assertSame($secret, $this->secretBox->open($stored));

        // A half-finished setup must not lock anybody out of their own account.
        self::assertFalse($this->service->isEnrolled($this->user));
        self::assertFalse($this->service->isEnrollmentBroken($this->user));
    }

    public function testConfirmEnrollmentRequiresAWorkingCode(): void
    {
        $this->service->beginEnrollment($this->user);

        self::assertNull($this->service->confirmEnrollment($this->user, '000000'));
        self::assertNull($this->service->confirmEnrollment($this->user, 'not-a-code'));
        self::assertFalse($this->service->isEnrolled($this->user));
    }

    public function testConfirmEnrollmentCompletesTheSetupAndIssuesRecoveryCodes(): void
    {
        $codes = $this->enroll();

        self::assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $codes);
        self::assertTrue($this->service->isEnrolled($this->user));
        self::assertNotNull($this->service->confirmedAt($this->user));
        self::assertSame(TwoFactorService::RECOVERY_CODE_COUNT, $this->service->recoveryCodesRemaining($this->user));
    }

    public function testConfirmingWithoutStartingIsRefused(): void
    {
        self::assertNull($this->service->confirmEnrollment($this->user, '123456'));
    }

    public function testStartingAgainWipesTheOldStateEntirely(): void
    {
        $this->enroll();

        $this->service->beginEnrollment($this->user);

        self::assertFalse($this->service->isEnrolled($this->user));
        self::assertSame(0, $this->service->recoveryCodesRemaining($this->user));
        self::assertNull($this->user->getDataValue('two_factor_last_counter'));
    }

    public function testDisableClearsEverything(): void
    {
        $this->enroll();

        $this->service->disable($this->user);

        self::assertFalse($this->service->isEnrolled($this->user));
        self::assertFalse($this->service->isEnrollmentBroken($this->user));
        self::assertNull($this->user->getDataValue('two_factor_secret'));
        self::assertSame(0, $this->service->recoveryCodesRemaining($this->user));
    }

    // --- verification -------------------------------------------------------

    public function testAValidCodeIsAccepted(): void
    {
        $this->enroll();
        sleep(TotpGenerator::PERIOD);

        self::assertTrue($this->service->verify($this->user, $this->code()));
    }

    public function testAWrongCodeIsRejected(): void
    {
        $this->enroll();

        self::assertFalse($this->service->verify($this->user, '000000'));
        self::assertFalse($this->service->verify($this->user, ''));
        self::assertFalse($this->service->verify($this->user, 'abcdef'));
    }

    public function testAnUnenrolledAccountVerifiesNothing(): void
    {
        self::assertFalse($this->service->verify($this->user, '123456'));
    }

    /**
     * A code is valid for thirty seconds. Accepting it twice would let anyone who
     * shoulder-surfed it, or who could replay the POST, in through the same window.
     */
    public function testTheSameCodeIsRefusedASecondTimeInsideItsWindow(): void
    {
        $this->enroll();
        sleep(TotpGenerator::PERIOD);

        $code = $this->code();

        self::assertTrue($this->service->verify($this->user, $code));
        self::assertFalse($this->service->verify($this->user, $code), 'Replay inside the window.');

        // Even a few seconds later, while the same step is still current.
        sleep(2);
        self::assertFalse($this->service->verify($this->user, $code));
    }

    public function testThePreviousStepIsRefusedAfterANewerOneWasUsed(): void
    {
        $this->enroll();
        sleep(TotpGenerator::PERIOD);

        $previous = $this->code(time() - TotpGenerator::PERIOD);
        self::assertTrue($this->service->verify($this->user, $this->code()));

        // Drift would normally accept the previous step; the used counter must not.
        self::assertFalse($this->service->verify($this->user, $previous));
    }

    public function testTheNextStepIsAcceptedOnceItArrives(): void
    {
        $this->enroll();
        sleep(TotpGenerator::PERIOD);
        self::assertTrue($this->service->verify($this->user, $this->code()));

        sleep(TotpGenerator::PERIOD * 2);

        self::assertTrue($this->service->verify($this->user, $this->code()));
    }

    public function testDriftIsClampedToASaneWindow(): void
    {
        $this->settings->put('security.twofactor_drift', 9999);
        $this->enroll();
        sleep(TotpGenerator::PERIOD * 30);

        // A code from thirty steps ago must not be accepted however the setting is
        // written; the service clamps drift to five periods.
        self::assertFalse($this->service->verify($this->user, $this->code(time() - TotpGenerator::PERIOD * 20)));
    }

    // --- recovery codes ------------------------------------------------------

    public function testRecoveryCodesLookLikeRecoveryCodesAndAreAllDistinct(): void
    {
        $codes = $this->enroll();

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $code);
        }

        self::assertCount(\count($codes), array_unique($codes));
    }

    public function testRecoveryCodesAreStoredHashedNotInTheClear(): void
    {
        $codes = $this->enroll();

        $stored = $this->user->getDataValue('two_factor_recovery');
        self::assertIsArray($stored);

        foreach ($codes as $code) {
            foreach ($stored as $hash) {
                self::assertStringNotContainsString($code, (string) $hash);
                self::assertStringNotContainsString(str_replace('-', '', $code), (string) $hash);
            }
        }

        // Keyed SHA-256 hex.
        foreach ($stored as $hash) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
        }
    }

    public function testARecoveryCodeAuthenticatesAndIsThenConsumed(): void
    {
        $codes = $this->enroll();

        self::assertTrue($this->service->verify($this->user, $codes[0]));
        self::assertSame(TwoFactorService::RECOVERY_CODE_COUNT - 1, $this->service->recoveryCodesRemaining($this->user));

        self::assertFalse($this->service->verify($this->user, $codes[0]), 'A recovery code is single use.');
    }

    public function testEachRecoveryCodeWorksExactlyOnce(): void
    {
        $codes = $this->enroll();

        foreach ($codes as $code) {
            self::assertTrue($this->service->verify($this->user, $code), $code);
        }

        self::assertSame(0, $this->service->recoveryCodesRemaining($this->user));
        self::assertFalse($this->service->verify($this->user, $codes[0]));
    }

    public function testRecoveryCodesAreAcceptedInAnyReadableSpelling(): void
    {
        $codes = $this->enroll();
        $code = $codes[0];

        self::assertTrue($this->service->verify($this->user, strtolower(str_replace('-', ' ', $code))));
    }

    public function testRegeneratingInvalidatesTheOldRecoveryCodes(): void
    {
        $old = $this->enroll();

        $new = $this->service->regenerateRecoveryCodes($this->user);

        self::assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $new);
        self::assertSame([], array_intersect($old, $new));
        self::assertFalse($this->service->verify($this->user, $old[0]));
        self::assertTrue($this->service->verify($this->user, $new[0]));
    }

    public function testARecoveryCodeFromAnotherAppSecretIsRejected(): void
    {
        $codes = $this->enroll();

        $other = new TwoFactorService(
            $this->totp,
            $this->secretBox,
            $this->settings,
            new RoleConfigManager(new CapabilityRegistry(), sys_get_temp_dir().'/cp-no-roles', new ArrayAdapter()),
            $this->createMock(EntityManagerInterface::class),
            $this->flood,
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
            'a-completely-different-kernel-secret',
        );

        self::assertFalse($other->verify($this->user, $codes[0]));
    }

    // --- flood limiting -------------------------------------------------------

    public function testVerificationAttemptsAreFloodLimited(): void
    {
        $this->enroll();
        sleep(TotpGenerator::PERIOD);

        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($this->service->verify($this->user, '000000'), 'attempt '.$i);
        }

        // The eleventh attempt is refused before the code is even looked at, so a
        // now-correct code is refused too.
        self::assertFalse($this->service->verify($this->user, $this->code()));
    }

    public function testASuccessfulVerificationResetsTheAttemptCounter(): void
    {
        $this->enroll();
        sleep(TotpGenerator::PERIOD);

        for ($i = 0; $i < 5; ++$i) {
            $this->service->verify($this->user, '000000');
        }

        self::assertTrue($this->service->verify($this->user, $this->code()));
        self::assertSame(0, $this->flood->count(FloodService::EVENT_TWOFACTOR, '42', 900));
    }

    // --- a secret that no longer opens ------------------------------------------

    /**
     * Regression: an account whose sealed secret could no longer be opened (an
     * APP_SECRET rotation, a truncated column, a tampered User::$data blob) read
     * as "never enrolled". The guard then fell straight through and the second
     * factor of every affected account switched itself off silently, while the
     * account kept reporting as protected.
     */
    public function testAnUndecryptableSecretIsReportedAsBrokenRatherThanAbsent(): void
    {
        $this->enroll();
        $this->user->setDataValue('two_factor_secret', 'v2:'.base64_encode(random_bytes(40)));

        self::assertFalse($this->service->isEnrolled($this->user));
        self::assertTrue(
            $this->service->isEnrollmentBroken($this->user),
            'The factor is broken, not absent — the caller must fail closed on it.',
        );
        self::assertFalse($this->service->verify($this->user, '123456'));
    }

    public function testAnUnconfirmedUndecryptableSecretIsNotReportedAsBroken(): void
    {
        // Never finished enrolling: there is nothing to fail closed about.
        $this->user->setDataValue('two_factor_secret', 'v2:'.base64_encode(random_bytes(40)));

        self::assertFalse($this->service->isEnrollmentBroken($this->user));
    }

    public function testAnAccountWithNoSecretIsNeverReportedAsBroken(): void
    {
        $this->user->setDataValue('two_factor_confirmed_at', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));

        self::assertFalse($this->service->isEnrollmentBroken($this->user));
    }

    // --- enforcement -----------------------------------------------------------

    public function testEnrollmentIsNotRequiredWhileEnforcementIsOff(): void
    {
        self::assertFalse($this->service->isPrivileged($this->user));
        self::assertFalse($this->service->isEnrollmentRequired($this->user));
    }

    public function testTheGlobalSwitchTurnsTheWholeFeatureOff(): void
    {
        $this->settings->put('security.twofactor_enabled', false);

        self::assertFalse($this->service->isEnabledGlobally());
        self::assertFalse($this->service->isEnrollmentRequired($this->user));
    }

    public function testTheProvisioningUriIsBuiltFromTheAccountEmail(): void
    {
        $secret = $this->service->beginEnrollment($this->user);

        $uri = $this->service->provisioningUri($this->user, $secret, 'CPalius');

        self::assertStringStartsWith('otpauth://totp/CPalius:ali%40example.com?', $uri);
        self::assertStringContainsString('secret='.$secret, $uri);
    }

    public function testStateLivesEntirelyOnTheUserEntity(): void
    {
        $this->enroll();

        // Law 6.3 hybrid model: everything is in User::$data, nothing in a side table.
        foreach (['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery', 'two_factor_last_counter'] as $key) {
            self::assertNotNull($this->user->getDataValue($key), $key);
        }

        self::assertFalse((new TwoFactorService(
            $this->totp,
            $this->secretBox,
            $this->settings,
            new RoleConfigManager(new CapabilityRegistry(), sys_get_temp_dir().'/cp-no-roles', new ArrayAdapter()),
            $this->createMock(EntityManagerInterface::class),
            $this->flood,
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
            self::APP_SECRET,
        ))->isEnrolled(new User('someone-else@example.com')));
    }
}
