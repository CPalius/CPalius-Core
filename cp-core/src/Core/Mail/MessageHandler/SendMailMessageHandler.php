<?php

declare(strict_types=1);

namespace App\Core\Mail\MessageHandler;

use App\Core\Mail\CpMailerService;
use App\Core\Mail\Message\SendMailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendMailMessageHandler
{
    public function __construct(
        private readonly CpMailerService $mailer,
    ) {
    }

    public function __invoke(SendMailMessage $message): void
    {
        $this->mailer->sendNow(
            $message->to,
            $message->subject,
            $message->htmlBody,
            $message->textBody,
            $message->mailLogId,
        );
    }
}
