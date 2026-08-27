<?php

// Aktif modüllerin listesi. Bu dosya statiktir; container henüz boot
// olmadan (bundles.php aşamasında) okunur, bu yüzden DB'ye bağımlı değildir.
// Bu dosya cp:module:activate / cp:module:deactivate komutları tarafından
// otomatik olarak güncellenir; elle düzenlenebilir ama format bozulmamalıdır.

return [
  Modules\Media\MediaModule::class,
  Modules\Menu\MenuModule::class,
  Modules\Blog\BlogModule::class,
  Modules\Forum\ForumModule::class,
];
