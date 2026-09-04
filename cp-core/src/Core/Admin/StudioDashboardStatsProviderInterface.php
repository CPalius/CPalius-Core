<?php

declare(strict_types=1);

namespace App\Core\Admin;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Studio Genel Bakış (/admin) için modül istatistik sözleşmesi.
 *
 * Manifesto Law 2.1/2.3 (Core Never Dies): StudioDashboardService çekirdek
 * dosyasıdır ve hiçbir modüle (Blog, Forum, Roadmap …) doğrudan bağımlı
 * OLAMAZ. Modüller bu arayüzü implemente eder; #[AutoconfigureTag] ile
 * tagged_iterator üzerinden toplanır. Modül kapalıysa provider container'a
 * hiç girmez — dashboard diğer modüllerle ayakta kalır.
 *
 * Her aktif içerik/özellik modülü bu sözleşmeyi karşılamalıdır; böylece
 * yeni modül eklendiğinde Studio dashboard otomatik olarak onun
 * istatistiklerini yakalar.
 *
 * Kullanım (modül tarafında):
 *   final class BlogStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
 * Ekstra services.yaml tag'i gerekmez.
 */
#[AutoconfigureTag('cpalius.studio.dashboard_stats_provider')]
interface StudioDashboardStatsProviderInterface
{
    /** Kararlı bölüm anahtarı: blog, forum, media, roadmap, … */
    public function getKey(): string;

    /** Görünen ad veya çeviri anahtarı. */
    public function getLabel(): string;

    public function getIcon(): string;

    /** Düşük = önce. */
    public function getPriority(): int;

    /**
     * Studio menü öğelerini bu modüle bağlamak için route önekleri
     * (ör. ['admin_posts_', 'admin_categories_', 'admin_blog_']).
     *
     * @return list<string>
     */
    public function getRoutePrefixes(): array;

    /**
     * Widget görünürlük kataloğu (kullanıcı gizleyebilir).
     *
     * @return list<array{widgetId: string, titleKey: string}>
     */
    public function getWidgetCatalog(): array;

    /**
     * Fail-safe: provider exception fırlatırsa StudioDashboardService
     * o katkıyı atlar.
     */
    public function buildContribution(): StudioDashboardContribution;
}
