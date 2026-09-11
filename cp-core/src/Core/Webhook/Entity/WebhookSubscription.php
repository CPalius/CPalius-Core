<?php

declare(strict_types=1);

namespace App\Core\Webhook\Entity;

use App\Core\Webhook\Repository\WebhookSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebhookSubscriptionRepository::class)]
#[ORM\Table(name: 'cp_webhook_subscriptions')]
class WebhookSubscription
{
    public const QUARANTINE_AFTER = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 191)]
    private string $label;

    #[ORM\Column(type: 'string', length: 2048)]
    private string $url;

    #[ORM\Column(name: 'secret_cipher', type: 'text')]
    private string $secretCipher;

    #[ORM\Column(name: 'secret_last4', type: 'string', length: 4)]
    private string $secretLast4;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $events;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'consecutive_failures', type: 'integer')]
    private int $consecutiveFailures = 0;

    #[ORM\Column(name: 'quarantined_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $quarantinedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $events
     */
    public function __construct(string $label, string $url, string $secretCipher, string $secretLast4, array $events)
    {
        $this->label = $label;
        $this->url = $url;
        $this->secretCipher = $secretCipher;
        $this->secretLast4 = $secretLast4;
        $this->events = $events;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getSecretCipher(): string
    {
        return $this->secretCipher;
    }

    public function getSecretLast4(): string
    {
        return $this->secretLast4;
    }

    /**
     * @return list<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function isActive(): bool
    {
        return $this->active && $this->quarantinedAt === null;
    }

    public function isQuarantined(): bool
    {
        return $this->quarantinedAt !== null;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function matchesEvent(string $event): bool
    {
        return \in_array($event, $this->events, true);
    }

    /**
     * @param list<string> $events
     */
    public function updateTarget(string $label, string $url, array $events): void
    {
        $this->label = $label;
        $this->url = $url;
        $this->events = $events;
    }

    public function replaceSecret(string $secretCipher, string $secretLast4): void
    {
        $this->secretCipher = $secretCipher;
        $this->secretLast4 = $secretLast4;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
    }

    public function recordFailure(): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= self::QUARANTINE_AFTER) {
            $this->active = false;
            $this->quarantinedAt = new \DateTimeImmutable();
        }
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        if ($active) {
            $this->quarantinedAt = null;
            $this->consecutiveFailures = 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'secretLast4' => $this->secretLast4,
            'events' => $this->events,
            'active' => $this->active,
            'quarantined' => $this->quarantinedAt !== null,
            'consecutiveFailures' => $this->consecutiveFailures,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
