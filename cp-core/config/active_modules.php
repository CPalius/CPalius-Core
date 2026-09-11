<?php

// Active modules. Static file, read before container boot (bundles.php); no DB.

return [
  Modules\Media\MediaModule::class,
  Modules\Menu\MenuModule::class,
  Modules\Blog\BlogModule::class,
  Modules\Forum\ForumModule::class,
  Modules\Roadmap\RoadmapModule::class,
  Modules\Widget\WidgetModule::class,
  Modules\Seo\SeoModule::class,
  Modules\Pages\PagesModule::class,
];
