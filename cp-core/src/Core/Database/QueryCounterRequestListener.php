<?php

declare(strict_types=1);

namespace App\Core\Database;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Her HTTP isteğinin en başında QueryCounter'ı sıfırlar. PHP-FPM/worker
 * ortamlarında servis container'ı (ve dolayısıyla QueryCounter singleton'ı)
 * istekler arasında hayatta kalabildiği için bu sıfırlama olmazsa, bir
 * önceki isteğin sorgu sayıları bir sonraki isteğe sızar.
 *
 * onlyMasterRequests: sub-request'lerde (ör. ESI/fragment render) sayaç
 * sıfırlanmaz — bir ana isteğin alt render'ları da AYNI bütçeye dahildir,
 * çünkü tarayıcıya giden tek bir HTTP yanıtının toplam DB maliyetini
 * ölçmek istiyoruz.
 */
final class QueryCounterRequestListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly QueryCounter $counter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->counter->reset();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
