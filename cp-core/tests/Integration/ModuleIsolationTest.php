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
 * "Core Never Dies" — live proof of Manifesto Law 2.2 and 2.3.
 * Kernel is booted manually with fixture modules; HttpKernelBrowser drives HTTP tests.
 */
#[CoversClass(Kernel::class)]
final class ModuleIsolationTest extends TestCase
{
    private ?IsolationTestKernel $kernel = null;

    private string $quarantineLog;

    protected function setUp(): void
    {
        // cp-core/tests/Integration -> tests -> cp-core -> project root
        $projectDir = \dirname(__DIR__, 3);
        $this->quarantineLog = $projectDir.'/cp-core/var/log/module_quarantine.log';

        // Clear quarantine log so each test produces its own evidence.
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

    // Claim 1 — Core boots

    /** Failure means the isolation shield is gone — boot exception propagates. */
    public function testKernelBootsDespiteModuleThatThrowsDuringBoot(): void
    {
        $this->kernel->boot();

        self::assertNotNull(
            $this->kernel->getContainer(),
            'Cekirdek, patlayan bir modul yuzunden boot olamadi — "Core Never Dies" ihlali.',
        );
    }

    // Claim 2 — Isolation is surgical, not wholesale

    /** Healthy sibling must boot in the same run — control group for isolation. */
    public function testHealthySiblingModuleStillBootsInTheSameRun(): void
    {
        $this->kernel->boot();

        self::assertGreaterThan(
            0,
            HealthyModule::bootCount(),
            'Saglam modul boot EDILMEDI — izolasyon cerrahi degil, toptan kapatma yapiyor.',
        );
    }

    /** Broken module stays in bundle list — isolation swallows boot() errors only. */
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

    // Claim 3 — Quarantine is logged and diagnosable (Law 2.3)

    /** Log must contain class name and real failure reason — silent swallow is worse. */
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

    // Claim 4 — Real HTTP endpoints stay up

    /** Kernel boot alone is not enough — a real request must return a response. */
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

    /** AACP recovery console must stay reachable — 302 redirect is OK, 500 is not. */
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

    /** Recovery endpoint responds without auth; wrong token must be rejected. */
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

    // Claim 5 — Isolation respects namespace boundary

    /** Core bundle failures must propagate — only Modules\ prefix errors are swallowed. */
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

    /** Core failures must not appear in quarantine log — quarantine is user-space only. */
    public function testCoreNamespaceFailureIsNotWrittenToQuarantineLog(): void
    {
        $kernel = new CoreFailureTestKernel('test', true);

        try {
            $kernel->boot();
        } catch (\RuntimeException) {
            // Expected — covered by the test above.
        } finally {
            $kernel->shutdown();
        }

        // Log may not exist — treat as empty string for a single assert.
        $log = is_file($this->quarantineLog)
            ? (string) file_get_contents($this->quarantineLog)
            : '';

        self::assertStringNotContainsString(
            BrokenModule::class,
            $log,
            'Cekirdek bundle hatasi karantinaya yazildi — karantina yalnizca kullanici alanina aittir.',
        );
    }

    // Helpers

    /** Boots kernel and rebuilds SQLite schema — required for HTTP tests that read DB. */
    private function bootWithSchema(): void
    {
        $this->kernel->boot();

        // framework.test exposes test.service_container for private services.
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
 * Test kernel adding fixture modules on top of the real core bundles.
 * parent::registerBundles() is called deliberately for a realistic boot graph.
 */
final class IsolationTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new HealthyModule();
        yield new BrokenBootModule();
    }

    /** Separate cache dir — fixture bundles produce a different service graph. */
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/cp-core/var/cache/test_isolation';
    }
}

/**
 * Kernel testing namespace boundary — broken bundle lives under App\Tests\Fixtures\, not Modules\.
 * Its boot failure must propagate, not be swallowed.
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
