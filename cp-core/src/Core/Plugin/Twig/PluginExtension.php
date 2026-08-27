<?php

declare(strict_types=1);

namespace App\Core\Plugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * FrontMenuExtension (cp-core/src/Core/Menu/Twig/FrontMenuExtension.php)
 * ile aynı desen: bu sınıf SADECE fonksiyon kaydı yapar, asıl mantık
 * PluginRuntime'da (RuntimeExtensionInterface, lazy-loaded) yaşar.
 *
 * is_safe: ['html'] BİLİNÇLİ OLARAK verilir: PluginRuntime::render()'ın
 * döndürdüğü HTML her zaman bir plugin'in KENDİ Twig şablonundan gelir
 * (kullanıcı girdisi değildir) — şablon yazarının her çağrıda ayrıca
 * "|raw" yazmasına gerek kalmaz. Bu, Manifesto Law 5.3'ü ihlal etmez:
 * kaçış (escaping) sorumluluğu, tıpkı cp_schema_org()/cp_menu() gibi,
 * içeriği üreten alt Twig şablonuna aittir.
 */
final class PluginExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_plugin', [PluginRuntime::class, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
