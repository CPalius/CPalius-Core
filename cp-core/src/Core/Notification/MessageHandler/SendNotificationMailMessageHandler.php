<?php

declare(strict_types=1);

namespace App\Core\Notification\MessageHandler;

use App\Core\Localization\Service\LocaleScope;
use App\Core\Localization\Service\UserLocaleResolver;
use App\Core\Mail\CpMailerService;
use App\Core\Mail\Template\CoreMailTemplates;
use App\Core\Mail\Template\MailTemplateRenderer;
use App\Core\Notification\Message\SendNotificationMailMessage;
use App\Core\Notification\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders one instant notification mail in the RECIPIENT's language.
 *
 * The locale carried on the message is only a hint (the dispatching channel
 * fills it from the recipient); when it is missing — an older message still in
 * the queue from before this shipped — the stored account preference decides,
 * never the worker's default. A Messenger worker has no request, so "the
 * current locale" there is whatever the kernel booted with.
 */
#[AsMessageHandler]
final class SendNotificationMailMessageHandler
{
    public function __construct(
        private readonly CpMailerService $mailer,
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly MailTemplateRenderer $mailTemplates,
        private readonly UserLocaleResolver $userLocale,
        private readonly LocaleScope $localeScope,
        private readonly UrlGeneratorInterface $urlGenerator,
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

        $locale = $message->locale ?? $this->userLocale->resolve($user);

        $template = sprintf('notification/email/%s.html.twig', $message->mailTemplate);
        if (!$this->twig->getLoader()->exists($template)) {
            $template = 'notification/email/generic.html.twig';
        }

        // One scope around both renders: the per-event subject and the event
        // body have to agree on a language, and the body's `|trans` calls read
        // the translator rather than an argument.
        [$eventSubject, $content] = $this->localeScope->run($locale, fn (): array => [
            $this->translator->trans(
                'notification.mail.subject.'.$message->eventKey,
                $this->stringParams($message->payload),
                locale: $locale,
            ),
            $this->twig->render($template, [
                'notification' => $notification,
                'user' => $user,
                'payload' => $message->payload,
                'eventKey' => $message->eventKey,
            ]),
        ]);

        $mail = $this->mailTemplates->render(
            CoreMailTemplates::NOTIFICATION_INSTANT,
            $locale,
            [
                'subject' => $eventSubject,
                'content' => $content,
                'inbox_url' => $this->urlGenerator->generate('account_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
            ['user' => $user],
        );

        $this->mailer->sendNow($message->to, $mail->subject, $mail->html, $mail->text);
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
