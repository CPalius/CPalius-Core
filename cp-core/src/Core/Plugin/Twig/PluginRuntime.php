<?php

declare(strict_types=1);

namespace App\Core\Plugin\Twig;

use App\Core\Plugin\PluginInterface;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginToggleRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * {{ cp_plugin('isim', {...}) }} çağrısının veri kaynağı.
 *
 * RuntimeExtensionInterface'i implement eder (FrontMenuRuntime/SchemaOrgRuntime
 * ile aynı zorunlu desen): Symfony'nin TwigBundle autoconfigure kuralı bu
 * arayüzü gören her servisi otomatik 'twig.runtime' etiketiyle işaretler
 * ve Twig'in RuntimeLoader'ına kaydeder. Bu arayüz OLMADAN servis, hiçbir
 * yerden doğrudan çağrılmadığı için Symfony'nin "kullanılmayan private
 * servisleri kaldır" derleme optimizasyonuyla container'dan tamamen
 * silinir ve `{{ cp_plugin(...) }}` çağrısı "Unable to load the runtime"
 * hatasıyla patlar (bu tam olarak arayüz eksikken yaşanan hatadır).
 *
 * "Core Never Dies" (Manifesto Law 2.1/2.3) fail-safe zinciri:
 *   1) İsimle eşleşen plugin yoksa            -> sessizce '' (sayfa kırılmaz).
 *   2) Plugin isActive() === false ise         -> sessizce '' (plugin'in
 *      kendi doğası/yapılandırma eksikliği nedeniyle çalışamaz demektir).
 *   3) PluginToggleRepository::isDisabled() true ise
 *        -> sessizce '' (Faz 4: AACP "Modül Eklentileri" sayfasından
 *           yönetici tarafından DB'de kapatılmış — bkz. PluginToggleRepository
 *           doküman notu, bu kontrol BİLİNÇLİ OLARAK plugin sınıflarının
 *           kendi isActive()'inden AYRI tutulur, tek merkezi yerde uygulanır).
 *   4) Plugin render() sırasında \Throwable atarsa
 *        -> yakalanır, cp-core/var/log/module_quarantine.log'a
 *           Kernel::quarantineModuleAtRuntime() ile BİREBİR aynı satır
 *           formatında ek bir satır düşülür, '' döner.
 * Hiçbir durumda bir plugin hatası tüm sayfayı 500'e düşürmez — tek bir
 * bozuk eklenti, o eklentinin göründüğü sidebar bloğunun boş kalmasından
 * fazlasına yol açmaz.
 */
final class PluginRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly PluginRegistry $pluginRegistry,
        private readonly PluginToggleRepository $pluginToggleRepository,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $name, array $context = []): string
    {
        $plugin = $this->pluginRegistry->getPlugin($name);

        if (!$plugin instanceof PluginInterface) {
            return '';
        }

        if (!$plugin->isActive()) {
            return '';
        }

        if ($this->pluginToggleRepository->isDisabled($name)) {
            return '';
        }

        try {
            return $plugin->render($context);
        } catch (Throwable $e) {
            $this->logPluginFailure($plugin, $e);

            return '';
        }
    }

    private function logPluginFailure(PluginInterface $plugin, Throwable $e): void
    {
        $this->logger->warning('Modül eklentisi render() aşamasında hata verdiği için atlandı.', [
            'plugin' => $plugin->getName(),
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s eklentisi render() aşamasında hata verdiği için çalışma anında atlandı. Sebep: %s',
            date('Y-m-d H:i:s'),
            $plugin->getName(),
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
