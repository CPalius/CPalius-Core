<?php

declare(strict_types=1);

namespace App\Core\Aacp;

/**
 * SystemWidgetProviderInterface'in ürettiği tek bir AACP Sistem Monitörü
 * kartının salt-veri temsili. Twig şablonu (aacp/system.html.twig) bunu
 * doğrudan <twig:cp:card>/<twig:cp:gauge> bileşenlerine map eder — provider
 * hiçbir Twig/HTML bilgisi taşımaz, sadece "ne gösterileceğini" bildirir.
 */
final class SystemWidgetData
{
    /**
     * @param string $title AACP kartının başlığı (ör. "Zamanlanmış Yayın").
     * @param int|float $value Kartta büyük punto gösterilecek ana sayı.
     * @param string|null $unit $value'nun yanına eklenecek birim (ör. 'MiB', '%').
     * @param string|null $description Kartın altında gösterilecek kısa açıklama.
     * @param string|null $linkRoute Kartın "detaya git" linki için route adı (Router::generate).
     * @param array<string, mixed> $linkRouteParams $linkRoute için route parametreleri.
     * @param 'default'|'danger' $variant twig:cp:card'ın variant prop'una aktarılır — 'danger' kırmızı vurgu.
     * @param float|null $gaugeMax Verilirse kart altında bir twig:cp:gauge çizilir ($value/$gaugeMax oranıyla).
     */
    public function __construct(
        public readonly string $title,
        public readonly int|float $value,
        public readonly ?string $unit = null,
        public readonly ?string $description = null,
        public readonly ?string $linkRoute = null,
        public readonly array $linkRouteParams = [],
        public readonly string $variant = 'default',
        public readonly ?float $gaugeMax = null,
    ) {
    }
}
