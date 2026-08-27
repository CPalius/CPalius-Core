<?php

declare(strict_types=1);

namespace App\Core\Hook\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * PluginExtension (cp-core/src/Core/Plugin/Twig/PluginExtension.php) ile
 * aynı desen: bu sınıf SADECE fonksiyon kaydı yapar, asıl mantık
 * HookRuntime'da (RuntimeExtensionInterface, lazy-loaded) yaşar.
 *
 * {{ cp_hook('tema.render.sidebar', context) }} çağrısı, HookContext
 * nesnesini (veya düz bir array'i) HookRuntime::render()'a iletir; dönüş
 * değeri her hook'un ürettiği HTML parçalarının birleşimidir.
 *
 * is_safe: ['html'] BİLİNÇLİ OLARAK verilir: PluginExtension ile aynı
 * gerekçe — hook'ların ürettiği HTML kendi şablonlarından/dosyalarından
 * gelir (kullanıcı girdisi değildir), kaçış sorumluluğu içeriği üreten
 * hook'a aittir.
 */
final class HookExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_hook', [HookRuntime::class, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
