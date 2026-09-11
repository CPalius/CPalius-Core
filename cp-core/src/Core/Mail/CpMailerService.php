<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Mail\Entity\MailLog;
use App\Core\Mail\Message\SendMailMessage;
use App\Core\Mail\Repository\MailLogRepository;
use App\Core\Settings\SettingsRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Dynamic SMTP sender configured via cp_settings.
 *
 * sendHtml() enqueues onto Messenger so the HTTP thread never waits on SMTP.
 * sendNow() / sendTestEmail() deliver immediately (AACP test mail, worker handlers).
 * Every outbound attempt is recorded in cp_mail_logs for AACP resend.
 */
final class CpMailerService
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailLogRepository $mailLogs,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settingsRegistry->get('mail.enabled') ?? false);
    }

    public function canSend(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $host = trim((string) ($this->settingsRegistry->get('mail.smtp_host') ?? ''));
        $from = trim((string) ($this->settingsRegistry->get('mail.from_email') ?? ''));

        return $host !== '' && $from !== '' && filter_var($from, \FILTER_VALIDATE_EMAIL);
    }

    /**
     * Enqueue an HTML mail for async delivery. Prefer this from request threads.
     *
     * @throws \RuntimeException when mail is not configured
     */
    public function sendHtml(string $to, string $subject, string $htmlBody, ?string $textBody = null): MailLog
    {
        if (!$this->canSend()) {
            throw new \RuntimeException('Mail is not configured or disabled.');
        }

        $log = new MailLog($to, $subject, $htmlBody, $textBody, MailLog::STATUS_QUEUED);
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new SendMailMessage(
            $to,
            $subject,
            $htmlBody,
            $textBody,
            $log->getId(),
        ));

        return $log;
    }

    /**
     * Deliver immediately over SMTP. Used by the Messenger worker and AACP test mail.
     *
     * @throws TransportExceptionInterface
     * @throws \RuntimeException when mail is not configured
     */
    public function sendNow(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?int $mailLogId = null,
    ): void {
        if (!$this->canSend()) {
            throw new \RuntimeException('Mail is not configured or disabled.');
        }

        $log = $this->resolveLog($mailLogId, $to, $subject, $htmlBody, $textBody);

        try {
            $fromEmail = (string) $this->settingsRegistry->get('mail.from_email');
            $fromName = trim((string) ($this->settingsRegistry->get('mail.from_name') ?? ''));

            $email = (new Email())
                ->from(new Address($fromEmail, $fromName !== '' ? $fromName : $fromEmail))
                ->to($to)
                ->subject($subject)
                ->html($htmlBody);

            if ($textBody !== null) {
                $email->text($textBody);
            }

            $mailer = new Mailer($this->createTransport());
            $mailer->send($email);
            $log->markSent();
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            $this->entityManager->flush();
            throw $e;
        }
    }

    /**
     * Re-queue or re-send from a stored MailLog row.
     *
     * @throws \RuntimeException when mail is not configured
     */
    public function resend(MailLog $log, bool $immediate = false): MailLog
    {
        if (!$this->canSend()) {
            throw new \RuntimeException('Mail is not configured or disabled.');
        }

        $log->markQueued();
        $this->entityManager->flush();

        if ($immediate) {
            $this->sendNow(
                $log->getTo(),
                $log->getSubject(),
                $log->getHtmlBody(),
                $log->getTextBody(),
                $log->getId(),
            );

            return $log;
        }

        $this->messageBus->dispatch(new SendMailMessage(
            $log->getTo(),
            $log->getSubject(),
            $log->getHtmlBody(),
            $log->getTextBody(),
            $log->getId(),
        ));

        return $log;
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendTestEmail(string $to): void
    {
        $this->sendNow(
            $to,
            'CPalius SMTP Test',
            '<p>Your SMTP settings are working.</p>',
            'Your SMTP settings are working.',
        );
    }

    private function resolveLog(
        ?int $mailLogId,
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody,
    ): MailLog {
        if ($mailLogId !== null) {
            $existing = $this->mailLogs->find($mailLogId);
            if ($existing instanceof MailLog) {
                return $existing;
            }
        }

        $log = new MailLog($to, $subject, $htmlBody, $textBody, MailLog::STATUS_QUEUED);
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function createTransport(): Transport\TransportInterface
    {
        return Transport::fromDsn($this->buildDsn());
    }

    private function buildDsn(): string
    {
        $host = trim((string) ($this->settingsRegistry->get('mail.smtp_host') ?? ''));
        $port = (int) ($this->settingsRegistry->get('mail.smtp_port') ?? 587);
        $encryption = (string) ($this->settingsRegistry->get('mail.smtp_encryption') ?? 'tls');
        $user = (string) ($this->settingsRegistry->get('mail.smtp_user') ?? '');
        $password = (string) ($this->settingsRegistry->get('mail.smtp_password') ?? '');

        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $auth = '';
        if ($user !== '') {
            $auth = rawurlencode($user);
            if ($password !== '') {
                $auth .= ':'.rawurlencode($password);
            }
            $auth .= '@';
        }

        return sprintf('%s://%s%s:%d', $scheme, $auth, $host, $port);
    }
}
