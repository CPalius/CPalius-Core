<?php

declare(strict_types=1);

namespace App\Core\Hook\Twig;

use App\Core\Hook\HookContext;
use App\Core\Hook\HookManager;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * {{ cp_hook('hook.point', {...}) }} çağrısının veri kaynağı.
 *
 * PluginRuntime ile aynı zorunlu desen: RuntimeExtensionInterface'i
 * implement eder, TwigBundle autoconfigure kuralı bu servisi otomatik
 * 'twig.runtime' etiketiyle işaretler.
 *
 * "Core Never Dies" fail-safe zinciri BURADA TEKRAR edilmez: gerçek
 * try/catch koruması zaten HookManager::trigger() içindedir (her tekil
 * hook için ayrı ayrı) — bu sınıf sadece Twig'den gelen serbest $context
 * array'ini bir HookContext'e sarar ve sonucun HTML çıktısını döner.
 */
final class HookRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly HookManager $hookManager,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $hookPoint, array $context = []): string
    {
        $hookContext = new HookContext($context);

        $result = $this->hookManager->trigger($hookPoint, $hookContext);

        return $result->getHtml();
    }
}
