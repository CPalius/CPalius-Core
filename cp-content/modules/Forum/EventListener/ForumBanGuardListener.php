<?php

declare(strict_types=1);

namespace Modules\Forum\EventListener;

use App\Entity\User;
use Modules\Forum\Service\ForumBanService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Forum-özel BAN (site geneli değil) uygulanan kullanıcıyı tüm ön yüz
 * forum rotalarından (ForumFrontController, ForumProfileController, ileride
 * eklenecek her yeni ön yüz controller'ı) merkezi olarak dışlar — her
 * action'a ayrı ayrı kontrol eklemek yerine, namespace'e göre TEK noktadan.
 * "Modules\Forum\Controller\Admin\" altındaki Studio controller'ları
 * bilinçli olarak HARİÇ tutulur (bir moderatör kendi kendini yasaklamış
 * olsa bile yönetim erişimini kaybetmemeli). MUTE, daha ince taneli olduğu
 * için burada değil, ilgili yazma action'larında (newTopic/reply/likePost)
 * ayrıca kontrol edilir.
 */
final class ForumBanGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ForumBanService $banService,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        $controllerObject = \is_array($controller) ? $controller[0] : $controller;
        $controllerClass = $controllerObject::class;

        $isForumFrontController = str_starts_with($controllerClass, 'Modules\\Forum\\Controller\\')
            && !str_starts_with($controllerClass, 'Modules\\Forum\\Controller\\Admin\\');

        if (!$isForumFrontController) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $ban = $this->banService->activeBanFor($user);
        if ($ban === null) {
            return;
        }

        throw new AccessDeniedHttpException(sprintf('Forumdan yasaklandınız: %s', $ban->getReason()));
    }
}
