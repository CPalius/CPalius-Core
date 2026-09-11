<?php

declare(strict_types=1);

namespace App\Core\Notification\MessageHandler;

use App\Core\Mail\CpMailerService;
use App\Core\Notification\Message\SendNotificationMailMessage;
use App\Core\Notification\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsMessageHandler]
final class SendNotificationMailMessageHandler
{
    public function __construct(
        private readonly CpMailerService $mailer,
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendNotificationMailMessage $message): void
    {
        if (!$this->mailer->canSend()) {
            return;
        }

        $notification = $this->notifications->find($message->notificationId);
        $user = $this->users->find($message->userId);
        if ($notification === null || $user === null) {
            return;
        }

        $subject = $this->translator->trans(
            'notification.mail.subject.'.$message->eventKey,
            $this->stringParams($message->payload),
            locale: $message->locale,
        );

        $template = sprintf('notification/email/%s.html.twig', $message->mailTemplate);
        if (!$this->twig->getLoader()->exists($template)) {
            $template = 'notification/email/generic.html.twig';
        }

        $html = $this->twig->render($template, [
            'notification' => $notification,
            'user' => $user,
            'payload' => $message->payload,
            'eventKey' => $message->eventKey,
        ]);

        $this->mailer->sendNow($message->to, $subject, $html);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string|int|float|bool>
     */
    private function stringParams(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
