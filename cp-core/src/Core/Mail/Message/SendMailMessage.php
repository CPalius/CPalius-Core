<?php

declare(strict_types=1);

namespace App\Core\Mail\Message;

/**
 * Queued HTML mail. The HTTP thread must never wait on SMTP — enqueue this
 * instead and let the Messenger worker call CpMailerService::sendNow().
 */
final class SendMailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly ?string $textBody = null,
        public readonly ?int $mailLogId = null,
    ) {
    }
}
