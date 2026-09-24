<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Module\ActiveModulesFileWriter;

/**
 * Modules a brand-new site starts with. Anything else stays on disk and is
 * switched on later from the admin. The developer's active_modules.php is
 * replaced only while the wizard runs.
 */
final class InstallDefaults
{
    /**
     * @var list<class-string>
     */
    public const MODULES = [
        \Modules\Media\MediaModule::class,
        \Modules\Menu\MenuModule::class,
        \Modules\Pages\PagesModule::class,
        \Modules\Blog\BlogModule::class,
        \Modules\Forum\ForumModule::class,
        \Modules\Seo\SeoModule::class,
    ];

    public static function writeActiveModules(string $projectDir): void
    {
        (new ActiveModulesFileWriter($projectDir.'/cp-core/config/active_modules.php'))
            ->replaceAll(self::MODULES);
    }
}
