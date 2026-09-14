<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Password\BreachChecker;
use App\Core\Security\Password\PasswordHistory;
use App\Core\Security\Password\PasswordPolicy;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * IdentityTranslator returns the message id unchanged, so the assertions below
 * read as the policy's own rejection reasons rather than as rendered prose.
 */
#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    private const TOO_SHORT = 'security.password.too_short';
    private const NEEDS_CLASSES = 'security.password.needs_classes';
    private const TOO_COMMON = 'security.password.too_common';
    private const RESEMBLES = 'security.password.resembles_identity';
    private const BREACHED = 'security.password.breached';
    private const BREACH_UNAVAILABLE = 'security.password.breach_unavailable';
    private const REUSED = 'security.password.reused';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function policy(array $settings = [], ?MockResponse $hibp = null): PasswordPolicy
    {
        $registry = new ArraySettings($settings + ['security.password_breach_check' => false]);

        $client = new MockHttpClient(static fn (): MockResponse => $hibp ?? new MockResponse('', ['http_code' => 503]));

        return new PasswordPolicy(
            $registry,
            new BreachChecker($client, new ArrayAdapter()),
            new PasswordHistory($this->connection, new PasswordHasherFactory([
                PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4],
            ])),
            new IdentityTranslator(),
        );
    }

    // --- bounds -------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function minLengthSettings(): iterable
    {
        yield 'unset uses the default' => [[], 10];
        yield 'configured value is honoured' => [['security.password_min_length' => 14], 14];
        yield 'below the floor is clamped up' => [['security.password_min_length' => 4], 8];
        yield 'zero is clamped up' => [['security.password_min_length' => 0], 8];
        yield 'a stored null falls back to the floor' => [['security.password_min_length' => null], 8];
        yield 'above the ceiling is clamped down' => [['security.password_min_length' => 500], 128];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('minLengthSettings')]
    public function testMinLengthIsClamped(array $settings, int $expected): void
    {
        self::assertSame($expected, $this->policy($settings)->minLength());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function requiredClassSettings(): iterable
    {
        yield 'unset uses the default' => [[], 3];
        yield 'configured value is honoured' => [['security.password_required_classes' => 2], 2];
        yield 'zero is clamped up to one' => [['security.password_required_classes' => 0], 1];
        yield 'more than four is clamped down' => [['security.password_required_classes' => 9], 4];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('requiredClassSettings')]
    public function testRequiredClassesIsClamped(array $settings, int $expected): void
    {
        self::assertSame($expected, $this->policy($settings)->requiredClasses());
    }

    public function testHistoryDepthAndMaxAgeAreNeverNegative(): void
    {
        $policy = $this->policy(['security.password_history_depth' => -3, 'security.password_max_age_days' => -1]);

        self::assertSame(0, $policy->historyDepth());
        self::assertSame(0, $policy->maxAgeDays());
    }

    // --- length --------------------------------------------------------------

    public function testATooShortPasswordIsTheOnlyReasonReported(): void
    {
        // Piling "needs more classes" on top of "too short" is noise: fixing the
        // length usually fixes the rest.
        $errors = $this->policy(['security.password_min_length' => 12])->validate('ab');

        self::assertSame([self::TOO_SHORT], $errors);
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // Ten Turkish characters are twenty bytes in UTF-8.
        $errors = $this->policy(['security.password_min_length' => 10, 'security.password_required_classes' => 1])
            ->validate('şğüöçşğüöç');

        self::assertNotContains(self::TOO_SHORT, $errors);
    }

    // --- character classes ---------------------------------------------------

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function characterClassCases(): iterable
    {
        yield 'lowercase only, one required' => ['abcdefghijkl', 1, true];
        yield 'lowercase only, two required' => ['abcdefghijkl', 2, false];
        yield 'lower + upper' => ['abcdefghijkL', 2, true];
        yield 'lower + upper, three required' => ['abcdefghijkL', 3, false];
        yield 'lower + upper + digit' => ['abcdefghijL9', 3, true];
        yield 'lower + upper + digit, four required' => ['abcdefghijL9', 4, false];
        yield 'all four classes' => ['abcdefghiL9!', 4, true];
        yield 'symbol counts as its own class' => ['abcdefghijk!', 2, true];
        yield 'unicode letters count as letters' => ['şğüöçşğüöçÇĞ', 2, true];
    }

    #[DataProvider('characterClassCases')]
    public function testCharacterClassRequirement(string $password, int $required, bool $accepted): void
    {
        $errors = $this->policy([
            'security.password_min_length' => 8,
            'security.password_required_classes' => $required,
        ])->validate($password);

        if ($accepted) {
            self::assertNotContains(self::NEEDS_CLASSES, $errors);
        } else {
            self::assertContains(self::NEEDS_CLASSES, $errors);
        }
    }

    // --- deny list -----------------------------------------------------------

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function denylistCases(): iterable
    {
        yield 'exact builtin entry' => ['password123', true];
        yield 'case is ignored' => ['PassWord123', true];
        yield 'surrounding whitespace is ignored' => ['  qwertyuiop  ', true];
        yield 'trailing digits are stripped before the lookup' => ['administrator2024', true];
        yield 'trailing exclamation marks are stripped too' => ['letmein!!!!!', true];
        yield 'project name' => ['cpalius1234', true];
        yield 'turkish entry' => ['sifre1234', true];
        yield 'an unrelated passphrase is fine' => ['gorgeous-tulip-pavement', false];
        yield 'a banned word in the middle is not an exact hit' => ['my-password-is-long', false];
    }

    #[DataProvider('denylistCases')]
    public function testBuiltinDenylist(string $password, bool $denied): void
    {
        $errors = $this->policy(['security.password_min_length' => 8, 'security.password_required_classes' => 1])
            ->validate($password);

        if ($denied) {
            self::assertContains(self::TOO_COMMON, $errors);
        } else {
            self::assertNotContains(self::TOO_COMMON, $errors);
        }
    }

    public function testOperatorDenylistIsHonoured(): void
    {
        $policy = $this->policy([
            'security.password_min_length' => 8,
            'security.password_required_classes' => 1,
            'security.password_denylist' => "AcmeCorp2024\nlocal-legend,another-entry",
        ]);

        self::assertContains(self::TOO_COMMON, $policy->validate('acmecorp2024'));
        self::assertContains(self::TOO_COMMON, $policy->validate('local-legend'));
        self::assertContains(self::TOO_COMMON, $policy->validate('another-entry'));
        self::assertNotContains(self::TOO_COMMON, $policy->validate('unrelated-entry'));
    }

    public function testAnEmptyOperatorDenylistChangesNothing(): void
    {
        $policy = $this->policy([
            'security.password_min_length' => 8,
            'security.password_required_classes' => 1,
            'security.password_denylist' => "  \n , \n ",
        ]);

        self::assertNotContains(self::TOO_COMMON, $policy->validate('gorgeous-tulip-pavement'));
    }

    // --- identity similarity -------------------------------------------------

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function identityCases(): iterable
    {
        yield 'contains the mailbox name' => ['xKeatonyz1!', ['keaton@example.com'], true];
        yield 'contains the domain' => ['xExampleyz1!', ['keaton@example.com'], true];
        yield 'contains the surname' => ['aQuicksilverB1!', ['Ada', 'Quicksilver'], true];
        yield 'case is ignored' => ['XKEATONYZ1!', ['keaton@example.com'], true];
        yield 'fragments shorter than four characters are ignored' => ['xLiyz-pavement1!', ['Li'], false];
        yield 'unrelated password' => ['gorgeous-tulip-1!', ['keaton@example.com'], false];
        yield 'no identity given' => ['gorgeous-tulip-1!', [], false];
    }

    /** @param list<string> $identity */
    #[DataProvider('identityCases')]
    public function testIdentitySimilarity(string $password, array $identity, bool $rejected): void
    {
        $errors = $this->policy(['security.password_min_length' => 8, 'security.password_required_classes' => 1])
            ->validate($password, $identity);

        if ($rejected) {
            self::assertContains(self::RESEMBLES, $errors);
        } else {
            self::assertNotContains(self::RESEMBLES, $errors);
        }
    }

    // --- breach corpus -------------------------------------------------------

    private const HIBP_PASSWORD = 'Tr0ub4dor&3-zxcvbn';
    private const HIBP_SUFFIX = 'B72E1E25A70B06E796A0B480E7B8EC60BAA';

    public function testABreachedPasswordIsRejected(): void
    {
        $policy = $this->policy(
            ['security.password_min_length' => 8, 'security.password_required_classes' => 1, 'security.password_breach_check' => true],
            new MockResponse(self::HIBP_SUFFIX.':12345'),
        );

        self::assertContains(self::BREACHED, $policy->validate(self::HIBP_PASSWORD));
    }

    public function testAnUnbreachedPasswordPasses(): void
    {
        $policy = $this->policy(
            ['security.password_min_length' => 8, 'security.password_required_classes' => 1, 'security.password_breach_check' => true],
            new MockResponse('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:9'),
        );

        self::assertSame([], $policy->validate(self::HIBP_PASSWORD));
    }

    public function testTheBreachCheckCanBeTurnedOffEntirely(): void
    {
        $policy = $this->policy(
            ['security.password_min_length' => 8, 'security.password_required_classes' => 1, 'security.password_breach_check' => false],
            new MockResponse(self::HIBP_SUFFIX.':12345'),
        );

        self::assertSame([], $policy->validate(self::HIBP_PASSWORD));
    }

    /**
     * A third-party outage must not stop people creating accounts — unless the
     * operator explicitly asked for the opposite.
     */
    public function testAnUnreachableCorpusFailsOpenByDefault(): void
    {
        $policy = $this->policy(
            ['security.password_min_length' => 8, 'security.password_required_classes' => 1, 'security.password_breach_check' => true],
            new MockResponse('', ['http_code' => 503]),
        );

        self::assertSame([], $policy->validate('gorgeous-tulip-pavement'));
    }

    public function testAnUnreachableCorpusFailsClosedWhenConfiguredTo(): void
    {
        $policy = $this->policy(
            [
                'security.password_min_length' => 8,
                'security.password_required_classes' => 1,
                'security.password_breach_check' => true,
                'security.password_breach_fail_closed' => true,
            ],
            new MockResponse('', ['http_code' => 503]),
        );

        self::assertSame([self::BREACH_UNAVAILABLE], $policy->validate('gorgeous-tulip-pavement'));
    }

    // --- reuse ---------------------------------------------------------------

    public function testAPreviouslyUsedPasswordIsRejectedForAKnownUser(): void
    {
        $user = SecurityTestDatabase::userWithId(42);
        $hasherFactory = new PasswordHasherFactory([
            PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4],
        ]);
        $hash = (new UserPasswordHasher($hasherFactory))->hashPassword($user, 'gorgeous-tulip-pavement');

        $policy = $this->policy([
            'security.password_min_length' => 8,
            'security.password_required_classes' => 1,
            'security.password_history_depth' => 5,
        ]);
        $policy->remember($user, $hash);

        self::assertContains(self::REUSED, $policy->validate('gorgeous-tulip-pavement', [], $user));
        self::assertNotContains(self::REUSED, $policy->validate('a-different-passphrase', [], $user));
    }

    public function testReuseIsNotCheckedWhenNoUserIsGiven(): void
    {
        $policy = $this->policy([
            'security.password_min_length' => 8,
            'security.password_required_classes' => 1,
            'security.password_history_depth' => 5,
        ]);

        self::assertSame([], $policy->validate('gorgeous-tulip-pavement'));
    }

    public function testRememberIsANoOpWhenHistoryIsDisabled(): void
    {
        $user = SecurityTestDatabase::userWithId(42);
        $policy = $this->policy(['security.password_history_depth' => 0]);

        $policy->remember($user, 'some-hash');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_password_history'));
    }

    // --- rotation ------------------------------------------------------------

    public function testExpiryIsOffWhenRotationIsDisabled(): void
    {
        $user = SecurityTestDatabase::userWithId(42);
        $user->setDataValue('password_changed_at', (new \DateTimeImmutable('-5 years'))->format(\DateTimeInterface::ATOM));

        self::assertFalse($this->policy(['security.password_max_age_days' => 0])->isExpired($user));
    }

    public function testAnOldPasswordIsExpiredAndARecentOneIsNot(): void
    {
        $policy = $this->policy(['security.password_max_age_days' => 90]);

        $old = SecurityTestDatabase::userWithId(42);
        $old->setDataValue('password_changed_at', (new \DateTimeImmutable('-100 days'))->format(\DateTimeInterface::ATOM));

        $recent = SecurityTestDatabase::userWithId(43, 'veli@example.com');
        $recent->setDataValue('password_changed_at', (new \DateTimeImmutable('-10 days'))->format(\DateTimeInterface::ATOM));

        self::assertTrue($policy->isExpired($old));
        self::assertFalse($policy->isExpired($recent));
    }

    /**
     * An unknown age must read as "not expired": forcing a rotation on every
     * legacy account at once would lock the whole user base out of the site.
     */
    public function testAnUnknownOrUnparseableTimestampIsNotExpired(): void
    {
        $policy = $this->policy(['security.password_max_age_days' => 90]);

        $never = SecurityTestDatabase::userWithId(42);
        $broken = SecurityTestDatabase::userWithId(43, 'veli@example.com');
        $broken->setDataValue('password_changed_at', 'not-a-date');
        $empty = SecurityTestDatabase::userWithId(44, 'ayse@example.com');
        $empty->setDataValue('password_changed_at', '');

        self::assertFalse($policy->isExpired($never));
        self::assertFalse($policy->isExpired($broken));
        self::assertFalse($policy->isExpired($empty));
    }

    // --- combinations --------------------------------------------------------

    public function testEveryApplicableReasonIsReportedTogether(): void
    {
        $policy = $this->policy([
            'security.password_min_length' => 8,
            'security.password_required_classes' => 4,
            'security.password_breach_check' => false,
        ]);

        // Long enough, but: one class only, on the deny list, and built from the
        // account's own mailbox name.
        $errors = $policy->validate('administrator', ['administrator@example.com']);

        self::assertContains(self::NEEDS_CLASSES, $errors);
        self::assertContains(self::TOO_COMMON, $errors);
        self::assertContains(self::RESEMBLES, $errors);
        self::assertCount(3, $errors);
    }

    public function testAStrongPasswordProducesNoErrors(): void
    {
        $policy = $this->policy(['security.password_min_length' => 10, 'security.password_required_classes' => 3]);

        self::assertSame([], $policy->validate('Gorgeous-Tulip-Pavement-7', ['keaton@example.com']));
    }
}
