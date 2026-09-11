<?php

declare(strict_types=1);

namespace App\Core\Webhook;

use App\Core\Security\SecretBox;
use App\Core\Webhook\Entity\WebhookSubscription;
use App\Core\Webhook\Repository\WebhookSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AACP-facing CRUD for outbound webhook subscriptions. The signing secret is
 * shown exactly once (on create / rotate); only its ciphertext and last 4 chars
 * are persisted. URLs are SSRF-checked at write time, not only at delivery.
 */
final class WebhookSubscriptionService
{
    public const SECRET_PREFIX = 'whsec_';
    public const MAX_EVENTS = 32;
    public const EVENT_PATTERN = '/^[a-z][a-z0-9_.]{0,99}$/';

    public function __construct(
        private readonly WebhookSubscriptionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretBox $secretBox,
        private readonly SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * @return list<WebhookSubscription>
     */
    public function list(): array
    {
        return $this->repository->findBy([], ['id' => 'DESC']);
    }

    public function find(int $id): ?WebhookSubscription
    {
        return $this->repository->find($id);
    }

    /**
     * @param list<string> $events
     *
     * @return array{secret: string, subscription: WebhookSubscription}
     *
     * @throws \InvalidArgumentException on invalid label, URL (incl. SSRF) or events
     */
    public function create(string $label, string $url, array $events): array
    {
        $label = $this->normalizeLabel($label);
        $url = trim($url);
        $events = $this->normalizeEvents($events);

        // Fail closed: an unsafe URL never reaches the database.
        $this->ssrfGuard->assertSafeUrl($url);

        $secret = self::SECRET_PREFIX.bin2hex(random_bytes(24));
        $subscription = new WebhookSubscription(
            $label,
            $url,
            $this->secretBox->seal($secret),
            substr($secret, -4),
            $events,
        );

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return ['secret' => $secret, 'subscription' => $subscription];
    }

    /**
     * @param list<string> $events
     *
     * @throws \InvalidArgumentException
     */
    public function update(int $id, string $label, string $url, array $events): WebhookSubscription
    {
        $subscription = $this->find($id);
        if (!$subscription instanceof WebhookSubscription) {
            throw new \InvalidArgumentException('Subscription not found.');
        }

        $label = $this->normalizeLabel($label);
        $url = trim($url);
        $events = $this->normalizeEvents($events);
        $this->ssrfGuard->assertSafeUrl($url);

        $subscription->updateTarget($label, $url, $events);
        $this->entityManager->flush();

        return $subscription;
    }

    /**
     * Returns the new plaintext secret (shown once) or null when the id is unknown.
     */
    public function rotateSecret(int $id): ?string
    {
        $subscription = $this->find($id);
        if (!$subscription instanceof WebhookSubscription) {
            return null;
        }

        $secret = self::SECRET_PREFIX.bin2hex(random_bytes(24));
        $subscription->replaceSecret($this->secretBox->seal($secret), substr($secret, -4));
        $this->entityManager->flush();

        return $secret;
    }

    public function setActive(int $id, bool $active): bool
    {
        $subscription = $this->find($id);
        if (!$subscription instanceof WebhookSubscription) {
            return false;
        }

        $subscription->setActive($active);
        $this->entityManager->flush();

        return true;
    }

    public function delete(int $id): bool
    {
        $subscription = $this->find($id);
        if (!$subscription instanceof WebhookSubscription) {
            return false;
        }

        $this->entityManager->remove($subscription);
        $this->entityManager->flush();

        return true;
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 191) {
            throw new \InvalidArgumentException('Label must be 1–191 characters.');
        }

        return $label;
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function normalizeEvents(array $events): array
    {
        $clean = [];
        foreach ($events as $event) {
            $event = strtolower(trim((string) $event));
            if ($event === '' || preg_match(self::EVENT_PATTERN, $event) !== 1) {
                continue;
            }
            $clean[$event] = true;
            if (\count($clean) >= self::MAX_EVENTS) {
                break;
            }
        }

        $names = array_keys($clean);
        if ($names === []) {
            throw new \InvalidArgumentException('At least one valid event name is required.');
        }

        return array_values($names);
    }
}
