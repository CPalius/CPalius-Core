<?php

declare(strict_types=1);

namespace App\Core\Webhook;

use App\Core\Queue\Entity\AsyncJob;
use App\Core\Queue\AsyncJobHandlerInterface;
use App\Core\Security\SecretBox;
use App\Core\Webhook\Entity\WebhookSubscription;
use App\Core\Webhook\Repository\WebhookSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OutboundWebhookJobHandler implements AsyncJobHandlerInterface
{
    public function __construct(
        private readonly WebhookSubscriptionRepository $subscriptions,
        private readonly SecretBox $secretBox,
        private readonly WebhookSigner $signer,
        private readonly OutboundWebhookHttpClient $httpClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === AsyncJob::TYPE_OUTBOUND_WEBHOOK;
    }

    public function handle(AsyncJob $job): void
    {
        $payload = $job->getPayload();
        $subscriptionId = (int) ($payload['subscription_id'] ?? 0);
        $envelope = $payload['envelope'] ?? null;
        if ($subscriptionId < 1 || !\is_array($envelope)) {
            throw new \RuntimeException('Malformed outbound webhook job.');
        }

        $subscription = $this->subscriptions->find($subscriptionId);
        if (!$subscription instanceof WebhookSubscription || !$subscription->isActive()) {
            return;
        }

        $secret = $this->secretBox->open($subscription->getSecretCipher());
        $timestamp = time();
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = $this->signer->sign($envelope, $secret, $timestamp);

        $result = $this->httpClient->post($subscription->getUrl(), $body, [
            WebhookSigner::HEADER => $signature,
            'X-CP-Webhook-Id' => (string) ($envelope['id'] ?? ''),
            'X-CP-Webhook-Event' => (string) ($envelope['event'] ?? ''),
        ]);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            $subscription->recordFailure();
            $this->entityManager->flush();
            throw new \RuntimeException('Webhook endpoint returned HTTP '.$result['status']);
        }

        $subscription->recordSuccess();
        $this->entityManager->flush();
    }
}
