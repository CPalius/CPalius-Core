<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use App\Core\Localization\Service\UserLocaleResolver;
use App\Core\Mail\CpMailerService;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Sends one mail template by hand, to a person, a role, or every active member.
 *
 * This is what makes a custom template worth creating: the shipped templates
 * all have code behind them that decides when they go out, and a template an
 * operator wrote has none. Here the operator is the trigger.
 *
 * Three rules it will not bend:
 *
 *   - Only active accounts. A banned or deactivated member is not someone the
 *     site should be mailing, whatever the operator selected.
 *   - Queued, never sent inline. A send to every member from a request thread
 *     would time out halfway through with no record of where it stopped;
 *     CpMailerService::sendHtml() writes a MailLog row per recipient first.
 *   - Rendered per recipient. The template is resolved in each recipient's own
 *     locale and with their own tokens, so `[user:display_name]` is their name
 *     and a Turkish member gets the Turkish wording.
 */
final class MailTemplateBroadcaster
{
    public const AUDIENCE_USER = 'user';
    public const AUDIENCE_ROLE = 'role';
    public const AUDIENCE_ALL = 'all';

    /**
     * A single send is capped so a mistyped audience cannot queue an unbounded
     * blast. Past the cap the operator is told what was skipped rather than
     * left guessing why half the site got the mail.
     */
    public const MAX_RECIPIENTS = 2000;

    public function __construct(
        private readonly MailTemplateRenderer $renderer,
        private readonly CpMailerService $mailer,
        private readonly UserRepository $userRepository,
        private readonly UserLocaleResolver $userLocale,
    ) {
    }

    /**
     * @param self::AUDIENCE_* $audience
     * @param string           $target   user email/username for AUDIENCE_USER, role id for AUDIENCE_ROLE
     *
     * @return array{queued: int, skipped: int, failed: int, capped: bool}
     *
     * @throws \RuntimeException when mail is off, the audience is empty, or the template has no wording
     */
    public function send(string $key, string $audience, string $target): array
    {
        if (!$this->mailer->canSend()) {
            throw new \RuntimeException('Mail is not configured or disabled.');
        }

        $recipients = $this->resolveRecipients($audience, $target);

        if ($recipients === []) {
            throw new \RuntimeException('No active recipient matched that selection.');
        }

        $capped = \count($recipients) > self::MAX_RECIPIENTS;
        $skipped = $capped ? \count($recipients) - self::MAX_RECIPIENTS : 0;
        $recipients = \array_slice($recipients, 0, self::MAX_RECIPIENTS);

        // Checked once, before anything is queued: a template whose every
        // language is blank would otherwise mail its own name to the whole site.
        $this->assertHasBody($key, $recipients[0]);

        $queued = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                $mail = $this->renderer->render(
                    $key,
                    $this->userLocale->resolve($recipient),
                    [],
                    ['user' => $recipient],
                );

                $this->mailer->sendHtml($recipient->getEmail(), $mail->subject, $mail->html, $mail->text);
                ++$queued;
            } catch (\Throwable) {
                // One bad address must not abandon the rest of the audience.
                ++$failed;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'failed' => $failed, 'capped' => $capped];
    }

    /**
     * @return list<User>
     */
    private function resolveRecipients(string $audience, string $target): array
    {
        $target = trim($target);

        $candidates = match ($audience) {
            self::AUDIENCE_USER => array_filter([$this->userRepository->findOneByEmailOrUsername($target)]),
            self::AUDIENCE_ROLE => $target !== '' ? $this->userRepository->findByRole($target) : [],
            self::AUDIENCE_ALL => $this->userRepository->findAll(),
            default => [],
        };

        $recipients = [];

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof User || !$candidate->isActive()) {
                continue;
            }

            if (filter_var($candidate->getEmail(), \FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $recipients[] = $candidate;
        }

        return $recipients;
    }

    /**
     * @throws \RuntimeException when the template renders with nothing in it
     */
    private function assertHasBody(string $key, User $sample): void
    {
        $mail = $this->renderer->render($key, $this->userLocale->resolve($sample), [], ['user' => $sample]);

        if (trim($mail->html) === '' && trim($mail->text) === '') {
            throw new \RuntimeException('This template has no body yet — write it in at least one language first.');
        }
    }
}
