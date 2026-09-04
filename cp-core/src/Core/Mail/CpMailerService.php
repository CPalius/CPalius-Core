<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Settings\SettingsRegistry;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * cp_settings üzerinden yapılandırılan dinamik SMTP gönderici.
 */
final class CpMailerService
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
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
     * @throws TransportExceptionInterface
     */
    public function sendHtml(string $to, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        if (!$this->canSend()) {
            throw new \RuntimeException('Mail is not configured or disabled.');
        }

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
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendTestEmail(string $to): void
    {
        $this->sendHtml(
            $to,
            'CPalius SMTP Test',
            '<p>SMTP ayarlarınız çalışıyor.</p>',
            'SMTP ayarlarınız çalışıyor.',
        );
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
