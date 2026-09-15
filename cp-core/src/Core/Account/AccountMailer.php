<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Localization\Service\UserLocaleResolver;
use App\Core\Mail\CpMailerService;
use App\Core\Mail\Template\MailTemplateRenderer;
use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends one registry mail template to one account, in that account's language.
 *
 * Extracted so registration and the identity-change queue share one answer to
 * "which language, and what happens when SMTP is down" instead of two. The
 * language is the RECIPIENT's — read from their stored preference, never from
 * the request — because the request that triggers an account mail is as often
 * an administrator's click as the member's own.
 */
final class AccountMailer
{
    public function __construct(
        private readonly CpMailerService $mailer,
        private readonly MailTemplateRenderer $templates,
        private readonly UserLocaleResolver $userLocale,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Queues the mail and says whether it got as far as the queue.
     *
     * Never throws. An unreachable SMTP host must not roll back the
     * registration or the approval that triggered it — the mail log records the
     * failure, and the caller decides whether a missing mail is worth a flash
     * message.
     *
     * @param array<string, string|int> $parameters
     */
    public function send(User $user, string $templateKey, array $parameters = []): bool
    {
        if (!$this->mailer->canSend()) {
            return false;
        }

        try {
            $mail = $this->templates->render(
                $templateKey,
                $this->userLocale->resolve($user),
                $parameters,
                ['user' => $user],
            );

            $this->mailer->sendHtml($user->getEmail(), $mail->subject, $mail->html, $mail->text);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function loginUrl(): string
    {
        return $this->urlGenerator->generate('account_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
