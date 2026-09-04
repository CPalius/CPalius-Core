<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Tests\Fixtures\BrokenModule\BrokenModule;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Modules\BrokenBoot\BrokenBootModule;
use Modules\Healthy\HealthyModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

/**
 * ═══════════════════════════════════════════════════════════════════════
 *  "CORE NEVER DIES" — Manifesto Law 2.2 ve 2.3'ün CANLI KANITI
 * ═══════════════════════════════════════════════════════════════════════
 *
 * CPalius'un en büyük mimari iddiası şudur: bir kullanıcı-alanı modülü
 * çökerse, çekirdek ve AACP AYAKTA KALIR. Denetim raporunda bu iddianın
 * kodda doğru uygulandığı ama HİÇBİR TESTLE korunmadığı tespit edildi —
 * yani ilk refactor'da sessizce kaybolabilecek bir garantiydi.
 *
 * Bu test o boşluğu kapatır ve iddiayı EN ZOR biçimde sınar: modül
 * sözdizimsel olarak kusursuzdur, autoloader onu yükler, BundleInterface
 * sözleşmesini eksiksiz uygular, lint:container ve PHPStan temiz geçer —
 * ve yalnızca ÇALIŞMA ANINDA, Kernel::boot() içinde patlar. Hiçbir statik
 * kontrol bunu yakalayamaz; yakalayabilecek tek şey izolasyon zırhıdır.
 *
 * Test ettiğimiz dört bağımsız iddia:
 *
 *   1. Çekirdek, patlayan modüle rağmen boot olur.
 *   2. Sağlam kardeş modül aynı çalıştırmada normal boot olur
 *      (yani izolasyon "her şeyi kapat" değil, cerrahi bir müdahale).
 *   3. Bozuk modül SESSİZCE kaybolmaz — module_quarantine.log'a
 *      gerçek sebebiyle yazılır (Law 2.3, yönetici teşhis edebilmeli).
 *   4. Gerçek HTTP uçları (ana sayfa ve /aacp) hâlâ yanıt üretir;
 *      500 DÖNMEZ.
 *
 * ── Neden WebTestCase değil ──────────────────────────────────────────────
 *
 * WebTestCase, KERNEL_CLASS ortam değişkeninden tek bir çekirdek sınıfı
 * boot eder. Burada bilerek FARKLI bir çekirdeğe (fixture modülleri
 * eklenmiş) ihtiyacımız var, üstelik boot işleminin KENDİSİ test edilen
 * davranış. Bu yüzden çekirdek elle örneklenir ve istekler
 * HttpKernelBrowser ile gerçek bir HTTP döngüsünden geçirilir.
 */
#[CoversClass(Kernel::class)]
final class ModuleIsolationTest extends TestCase
{
    private ?IsolationTestKernel $kernel = null;

    private string $quarantineLog;

    protected function setUp(): void
    {
        // cp-core/tests/Integration -> tests -> cp-core -> proje kökü
        $projectDir = \dirname(__DIR__, 3);
        $this->quarantineLog = $projectDir.'/cp-core/var/log/module_quarantine.log';

        // Her test kendi karantina logunu yazmalı: önceki testten kalan
        // satırlar, testin kendi kanıtını üretmeden geçmesine yol açardı.
        if (is_file($this->quarantineLog)) {
            @unlink($this->quarantineLog);
        }

        HealthyModule::resetBootCount();

        $this->kernel = new IsolationTestKernel('test', true);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    // ═════════════════════════════════════════════════════════════════════
    // İddia 1 — Çekirdek boot olur
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Bu testin başarısız olması, izolasyon zırhının kaybolduğu anlamına
     * gelir: BrokenBootModule::boot()'un fırlattığı RuntimeException
     * doğrudan buraya yükselir ve test ölümcül bir hatayla düşer.
     */
    public function testKernelBootsDespiteModuleThatThrowsDuringBoot(): void
    {
        $this->kernel->boot();

        self::assertNotNull(
            $this->kernel->getContainer(),
            'Cekirdek, patlayan bir modul yuzunden boot olamadi — "Core Never Dies" ihlali.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // İddia 2 — İzolasyon cerrahidir, toptan değil
    // ═════════════════════════════════════════════════════════════════════

    /**
     * "Bozuk modül izole edildi" iddiası, tek başına "tüm modüller
     * kapatıldı" senaryosundan ayırt edilemez. Kontrol grubu şart:
     * sağlam kardeş AYNI çalıştırmada gerçekten boot edilmeli.
     */
    public function testHealthySiblingModuleStillBootsInTheSameRun(): void
    {
        $this->kernel->boot();

        self::assertGreaterThan(
            0,
            HealthyModule::bootCount(),
            'Saglam modul boot EDILMEDI — izolasyon cerrahi degil, toptan kapatma yapiyor.',
        );
    }

    /**
     * Bozuk modül yine de bundle listesinde kalır; çünkü izolasyon onu
     * listeden çıkarmaz, yalnızca boot() hatasını yutar. Bu ayrım önemli:
     * modülün servisleri derlenmiştir, sadece çalışma anı başlatması
     * başarısız olmuştur.
     */
    public function testBothFixtureModulesAreRegisteredAsBundles(): void
    {
        $this->kernel->boot();

        $bundleClasses = array_map(
            static fn (object $bundle): string => $bundle::class,
            array_values($this->kernel->getBundles()),
        );

        self::assertContains(HealthyModule::class, $bundleClasses);
        self::assertContains(BrokenBootModule::class, $bundleClasses);
    }

    // ═════════════════════════════════════════════════════════════════════
    // İddia 3 — Karantina sessiz değil, teşhis edilebilir (Law 2.3)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Bir hatayı yutup hiçbir iz bırakmamak, çökmekten daha kötüdür:
     * yönetici modülün neden çalışmadığını asla öğrenemez. Bu yüzden
     * logda hem SINIF ADI hem GERÇEK SEBEP bulunmalıdır.
     */
    public function testBrokenModuleIsQuarantinedWithItsRealReason(): void
    {
        $this->kernel->boot();

        self::assertFileExists(
            $this->quarantineLog,
            'Bozuk modul SESSIZCE yutuldu — karantina logu hic olusmadi (Law 2.3 ihlali).',
        );

        $log = (string) file_get_contents($this->quarantineLog);

        self::assertStringContainsString(
            BrokenBootModule::class,
            $log,
            'Karantina logu, patlayan modulun sinif adini icermeli.',
        );
        self::assertStringContainsString(
            BrokenBootModule::FAILURE_MESSAGE,
            $log,
            'Karantina logu, hatanin GERCEK sebebini icermeli — genel bir mesaj yetmez.',
        );
        self::assertStringNotContainsString(
            HealthyModule::class,
            $log,
            'Saglam modul karantinaya alinmis gorunuyor — yanlis pozitif.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // İddia 4 — Gerçek HTTP uçları ayakta
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Asıl kanıt burada: kernel'in "boot oldu" demesi yetmez, gerçek bir
     * istek gerçek bir yanıt üretmelidir.
     */
    public function testHomepageStillRespondsWhileAModuleIsBroken(): void
    {
        $this->bootWithSchema();

        $browser = new HttpKernelBrowser($this->kernel);
        $browser->request('GET', '/');

        $status = $browser->getResponse()->getStatusCode();

        self::assertLessThan(
            500,
            $status,
            sprintf('Ana sayfa %d dondu — bozuk bir modul cekirdegi cokertti.', $status),
        );
    }

    /**
     * AACP, Manifesto Law 2.3'ün merkezindeki kurtarma konsoludur: TÜM
     * modüller çökse bile erişilebilir kalmalıdır.
     *
     * Kimlik doğrulaması olmadan beklenen yanıt 302'dir (giriş sayfasına
     * yönlendirme) — ve bu, tam olarak istediğimiz kanıttır: güvenlik
     * duvarı ÇALIŞIYOR, yani çekirdeğin istek yaşam döngüsü sağlam.
     * Beklenmeyen ve kabul edilemez olan 500'dür.
     */
    public function testAacpRemainsReachableWhileAModuleIsBroken(): void
    {
        $this->bootWithSchema();

        $browser = new HttpKernelBrowser($this->kernel);
        $browser->followRedirects(false);
        $browser->request('GET', '/aacp');

        $status = $browser->getResponse()->getStatusCode();

        self::assertLessThan(
            500,
            $status,
            sprintf('/aacp %d dondu — kurtarma konsolu coktu, Law 2.3 ihlali.', $status),
        );
        self::assertContains(
            $status,
            [200, 301, 302, 303, 307, 401, 403],
            sprintf('/aacp beklenmeyen bir durum kodu dondu: %d', $status),
        );
    }

    /**
     * Kurtarma konsolunun token korumalı ucu (Law 2.3), oturum ve
     * veritabanı olmadan da yanıt üretmelidir. Yanlış token ile
     * erişimin REDDEDİLDİĞİNİ de doğrular — uç açık olmalı ama korumasız
     * değil.
     */
    public function testRecoveryEndpointRespondsWithoutAuthentication(): void
    {
        $this->bootWithSchema();

        $browser = new HttpKernelBrowser($this->kernel);
        $browser->followRedirects(false);
        $browser->request('GET', '/aacp/recovery', ['token' => 'kesinlikle-yanlis-token']);

        $status = $browser->getResponse()->getStatusCode();

        self::assertLessThan(
            500,
            $status,
            sprintf('/aacp/recovery %d dondu — kurtarma kapisi coktu.', $status),
        );
        self::assertNotSame(
            200,
            $status,
            'Yanlis token ile kurtarma konsoluna erisim VERILDI — kritik guvenlik hatasi.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // İddia 5 — İzolasyon, namespace sınırına SAYGI DUYAR
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Bu, testin en ince ve en önemli iddiasıdır.
     *
     * "Core Never Dies" bir toptan try/catch DEĞİLDİR. Kernel::boot()
     * yalnızca "Modules\" ön ekli bundle'ların hatalarını yutar
     * (Kernel::MODULE_NAMESPACE_PREFIX). Bir ÇEKİRDEK bundle'ı boot
     * sırasında patlarsa, bu gerçek bir çekirdek arızasıdır ve OLDUĞU
     * GİBİ YÜKSELMELİDİR — sessizce yutulup sistemin yarım yamalak
     * çalışmasına izin verilmemelidir.
     *
     * Bu ayrım olmasaydı izolasyon, gerçek hataları da gizleyen bir
     * "her şeyi yut" mekanizmasına dönüşürdü: en tehlikeli hata sınıfı,
     * kimsenin görmediği hatadır.
     *
     * Fixture bilinçli olarak "App\Tests\Fixtures\..." altındadır, yani
     * modül ön ekini TAŞIMAZ — BrokenBootModule ile tek farkı budur ve
     * davranış tamamen tersine döner.
     */
    public function testCoreNamespaceBundleFailureIsNotSwallowed(): void
    {
        $kernel = new CoreFailureTestKernel('test', true);

        try {
            $kernel->boot();
            self::fail(
                'Cekirdek namespace\'indeki bir bundle hatasi YUTULDU. Izolasyon '
                .'artik namespace sinirina saygi duymuyor — gercek cekirdek '
                .'arizalari sessizce gizleniyor demektir.',
            );
        } catch (\RuntimeException $e) {
            self::assertSame(
                BrokenModule::FAILURE_MESSAGE,
                $e->getMessage(),
                'Beklenen cekirdek hatasi yukselmedi.',
            );
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * Çekirdek hatası yutulmadığına göre, karantina loguna da
     * YAZILMAMALIDIR: karantina bir kullanıcı-alanı kavramıdır.
     */
    public function testCoreNamespaceFailureIsNotWrittenToQuarantineLog(): void
    {
        $kernel = new CoreFailureTestKernel('test', true);

        try {
            $kernel->boot();
        } catch (\RuntimeException) {
            // Beklenen: yukarıdaki test bunu zaten doğruluyor.
        } finally {
            $kernel->shutdown();
        }

        // Log dosyası hiç oluşmamış olabilir; o durum da iddiayı sağlar.
        // Boş dizeye düşürerek tek bir gerçek assert ile ifade ediyoruz —
        // "koşullu assert" yerine, her yolda anlamlı bir kontrol.
        $log = is_file($this->quarantineLog)
            ? (string) file_get_contents($this->quarantineLog)
            : '';

        self::assertStringNotContainsString(
            BrokenModule::class,
            $log,
            'Cekirdek bundle hatasi karantinaya yazildi — karantina yalnizca kullanici alanina aittir.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Yardımcılar
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Çekirdeği boot eder ve SQLite test veritabanında şemayı sıfırdan
     * kurar. HTTP testleri için gereklidir: ana sayfa ayarları, forum
     * istatistiklerini ve içerik sayılarını veritabanından okur.
     *
     * Şema, boot ETMEYEN testlerde bilinçli olarak kurulmaz — bu maliyeti
     * yalnızca ona ihtiyaç duyan testler öder.
     */
    private function bootWithSchema(): void
    {
        $this->kernel->boot();

        // framework.test: true sayesinde "test.service_container",
        // normalde private olan servislere erişim verir.
        $container = $this->kernel->getContainer()->get('test.service_container');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ($metadata === []) {
            return;
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}

/**
 * Fixture modüllerini gerçek çekirdeğin üzerine ekleyen test çekirdeği.
 *
 * parent::registerBundles() bilinçli olarak çağrılır: testin kanıtladığı
 * şey, GERÇEK uygulamanın (tüm çekirdek bundle'ları ve gerçek modülleriyle
 * birlikte) bozuk bir modüle rağmen ayakta kaldığıdır. Yalnızca fixture'lar
 * yüklenmiş yapay bir çekirdek, çok daha zayıf bir iddia olurdu.
 */
final class IsolationTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new HealthyModule();
        yield new BrokenBootModule();
    }

    /**
     * Ayrı önbellek dizini ZORUNLUDUR: bu çekirdeğin servis grafiği
     * (fixture bundle'ları yüzünden) standart test çekirdeğininkinden
     * farklıdır. Aynı dizini paylaşsalardı, biri diğerinin derlenmiş
     * container'ını okuyup fixture'ları hiç görmeyebilir ya da tersine,
     * normal testler fixture modüllerini yüklenmiş bulabilirdi.
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/cp-core/var/cache/test_isolation';
    }
}

/**
 * İzolasyonun namespace sınırını test eden çekirdek.
 *
 * IsolationTestKernel'den tek farkı, eklenen bozuk bundle'ın
 * "Modules\" DEĞİL "App\Tests\Fixtures\" altında olmasıdır. Kernel::boot()
 * yalnızca modül ön ekini taşıyan bundle'ları izole ettiği için, buradaki
 * hata yutulmamalı ve olduğu gibi yükselmelidir.
 */
final class CoreFailureTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new BrokenModule();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/cp-core/var/cache/test_core_failure';
    }
}
