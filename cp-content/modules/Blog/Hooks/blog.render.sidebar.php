<?php

declare(strict_types=1);

/**
 * Faz 7A örnek Flat-File Kanca (Cotonti tarzı): "blog.render.sidebar"
 * noktasına bağlı, DI konteynerine hiç ihtiyaç duymayan basit bir hook.
 *
 * Bu dosya HookManager::includeIsolated() içinde kapatılmış, izole bir
 * Closure scope'unda çalıştırılır: SADECE parametre olarak enjekte edilen
 * yerel $context değişkenini görür, çağıranın scope'undaki hiçbir
 * değişkene erişemez.
 *
 * Çağıran taraf (bkz. cp_hook('blog.render.sidebar', {...})) $context
 * üzerinden istediği veriyi okuyabilir; bu örnek $context->appendHtml()
 * ile üretilen HTML'i biriktirir ve GÜNCELLENMİŞ $context'i döner —
 * HookManager, dönüş değeri bir HookContext ise onu bir sonraki hook'a
 * aktarır, değilse mevcut $context ile devam eder.
 */
return (static function (App\Core\Hook\HookContext $context): App\Core\Hook\HookContext {
    $siteName = (string) $context->get('site_name', 'CPalius CMF');

    return $context->appendHtml(sprintf(
        '<div class="cp-hook-blog-sidebar"><p>%s — Blog modülünden flat-file kanca ile eklendi.</p></div>',
        htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
    ));
})($context);
