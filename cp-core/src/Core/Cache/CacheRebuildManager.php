<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * AACP "Önbellek ve Yeniden Derleme" panelinin ve Studio'nun hızlı
 * "Önbelleği Temizle" butonunun (bkz. admin/layout.html.twig) tek servis
 * kaynağı. Üç işlem BİLİNÇLİ OLARAK birbirinden bağımsız metotlardır
 * (tek bir dev "rebuildEverything()" değil): AACP panelindeki üç ayrı
 * buton, üç ayrı sonucu (ayrı ayrı başarı/hata) kullanıcıya gösterebilmeli
 * — Manifesto Law 2.3 (Safe Mode & Recovery Console) ruhuyla, bir işlemin
 * başarısız olması diğerini engellememelidir.
 *
 * cp-core'a (bir modüle DEĞİL) yerleştirildi: cache/opcache/asset rebuild
 * çekirdek bir altyapı kaygısıdır, herhangi bir modülün varlığına bağımlı
 * olamaz.
 *
 * NOT: cache.pool etiketiyle #[TaggedIterator] KULLANILMAZ — bu tag
 * container'daki "cache.adapter.system" gibi soyut (abstract) servis
 * tanımlarını da kapsar ve TaggedIterator bunları derleme zamanında
 * somutlaştırmaya çalışırken "must not be abstract" hatasıyla TÜM
 * container derlemesini çökertir. Bunun yerine tek, somut ve adı bilinen
 * "cache.app" servisi doğrudan enjekte edilir.
 */
final class CacheRebuildManager
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $appCache,
    ) {
    }

    /**
     * Symfony'nin dosya sistemi cache'ini (var/cache/{env}) temizler.
     * "cache:clear" konsol komutunu Process ile tetiklemek yerine
     * BİLİNÇLİ OLARAK doğrudan "cache.app" havuzunu ve var/cache/{env}
     * altındaki güvenle silinebilir alt dizinleri temizler: cache:clear
     * komutu kendi çalıştığı PHP process'inin container'ını "warmup" için
     * yeniden derlemeye çalışır — bu da AJAX isteğinin İÇİNDEN yeni bir
     * alt süreç açıp o süreç bitene kadar bekleme riskini (ve olası kilit
     * çakışmasını, aynı anda çalışan asıl istek + spawn edilen komutun
     * aynı var/cache dizinine yazması) taşır. Doğrudan pool clear +
     * dizin temizliği, aynı isteğin PHP process'i içinde güvenle çalışır.
     *
     * @return array{success: bool, output: string}
     */
    public function clearSymfonyCache(): array
    {
        $log = [];

        try {
            $this->appCache->clear();
            $log[] = '[OK] Uygulama önbelleği (cache.app) temizlendi.';
        } catch (\Throwable $e) {
            $log[] = sprintf('[HATA] cache.app temizlenemedi: %s', $e->getMessage());
        }

        $log = array_merge($log, $this->purgeCacheDirectory());

        $hasFailure = array_filter($log, static fn (string $line) => str_starts_with($line, '[HATA]')) !== [];

        return [
            'success' => !$hasFailure,
            'output' => implode(PHP_EOL, $log),
        ];
    }

    /**
     * var/cache/{env}/pools ve var/cache/{env}/twig gibi dosya tabanlı
     * artıkları siler — clearSymfonyCache()'in devamı, ayrı bir public
     * metot DEĞİL çünkü tek başına anlamlı bir kullanıcı eylemi değil.
     *
     * @return list<string>
     */
    private function purgeCacheDirectory(): array
    {
        $cacheDir = $this->projectDir.'/cp-core/var/cache/'.$this->environment;
        $filesystem = new Filesystem();

        // container/routing derlemesini (App_KernelXxxContainer.php vb.)
        // asla silmeyiz — bu dosyalar bir sonraki isteğin BAŞLAYABİLMESİ
        // için gereklidir, silinirse (ve dev'de debug:false değilse) 500
        // hatası üretir. Sadece güvenle yeniden üretilebilecek, kullanıcı
        // içeriğine bağlı alt dizinleri (pools, twig, asset_mapper, doctrine)
        // hedef alırız.
        $purgeableSubdirs = ['pools', 'twig', 'asset_mapper', 'doctrine', 'jit', 'profiler'];

        $log = [];
        foreach ($purgeableSubdirs as $subdir) {
            $path = $cacheDir.'/'.$subdir;
            if (!$filesystem->exists($path)) {
                continue;
            }

            try {
                $filesystem->remove($path);
                $log[] = sprintf('[OK] Dizin temizlendi: var/cache/%s/%s', $this->environment, $subdir);
            } catch (\Throwable $e) {
                $log[] = sprintf('[HATA] var/cache/%s/%s silinemedi: %s', $this->environment, $subdir, $e->getMessage());
            }
        }

        return $log;
    }

    /**
     * opcache_reset() PHP process'e ÖZGÜDÜR: bu istek PHP-FPM worker'ı
     * neyse SADECE onun opcode cache'ini sıfırlar. Bu, tek-worker'lı
     * yerel geliştirme (Laragon php -S / built-in server) için yeterlidir
     * ama çok-worker'lı bir prod PHP-FPM havuzunda diğer worker'ların
     * opcache'i etkilenmez — bu sınırlama fail-safe olarak dürüstçe
     * mesajda belirtilir, sahte bir "tüm sunucu temizlendi" iddiası
     * asla üretilmez.
     *
     * @return array{success: bool, output: string}
     */
    public function resetOpcache(): array
    {
        if (!\function_exists('opcache_reset')) {
            return [
                'success' => false,
                'output' => '[HATA] OPcache eklentisi bu PHP kurulumunda yüklü değil.',
            ];
        }

        $result = @opcache_reset();

        if ($result === false) {
            return [
                'success' => false,
                'output' => '[HATA] opcache_reset() başarısız oldu (opcache.enable=0 olabilir).',
            ];
        }

        return [
            'success' => true,
            'output' => '[OK] OPcache sıfırlandı (yalnızca bu isteği işleyen PHP worker\'ı için geçerlidir).',
        ];
    }

    /**
     * "php cp-core/bin/console tailwind:build" komutunu Process ile
     * senkron (arka planda değil, isteği tamamlanana kadar) çalıştırır.
     * AACP panelindeki AJAX çağrısı zaten kullanıcıya "işleniyor" durumu
     * gösterdiği için burada gerçek bir background/queue mekanizması
     * (henüz kurulu olmayan symfony/messenger, bkz. AACPController::
     * readQueueStatus() docblock'u) İCAT EDİLMEZ — Process'in kendi
     * senkron run() 'ı, sonucu doğrudan aynı HTTP response'a taşımak
     * için yeterli ve daha basittir (YAGNI).
     *
     * @return array{success: bool, output: string}
     */
    public function rebuildAssets(): array
    {
        $process = new Process(
            [\PHP_BINARY, $this->projectDir.'/cp-core/bin/console', 'tailwind:build', '--env='.$this->environment],
            $this->projectDir,
            null,
            null,
            120,
        );

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            return [
                'success' => false,
                'output' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'output' => $process->getOutput().$process->getErrorOutput(),
        ];
    }
}
