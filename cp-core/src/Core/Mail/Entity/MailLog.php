<?php

declare(strict_types=1);

namespace App\Core\Mail\Entity;

use App\Core\Mail\Repository\MailLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable outbound mail audit with resend support (T3.5).
 */
#[ORM\Entity(repositoryClass: MailLogRepository::class)]
#[ORM\Table(name: 'cp_mail_logs')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_mail_log_status_created')]
class MailLog
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'recipient', type: 'string', length: 255)]
    private string $to;

    #[ORM\Column(type: 'string', length: 255)]
    private string $subject;

    #[ORM\Column(name: 'html_body', type: 'text')]
    private string $htmlBody;

    #[ORM\Column(name: 'text_body', type: 'text', nullable: true)]
    private ?string $textBody;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        string $status = self::STATUS_QUEUED,
    ) {
        $this->to = $to;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markQueued(): void
    {
        $this->status = self::STATUS_QUEUED;
        $this->error = null;
    }

    public function markSent(): void
    {
        ++$this->attempts;
        $this->status = self::STATUS_SENT;
        $this->error = null;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        ++$this->attempts;
        $this->status = self::STATUS_FAILED;
        $this->error = mb_substr($error, 0, 4000);
    }
}
