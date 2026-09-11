<?php

declare(strict_types=1);

namespace App\Core\Webhook;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bounded HTTPS POST for webhook delivery.
 *
 * Two independent SSRF layers, because DNS can change between them (rebinding):
 *   1. SsrfGuard::assertSafeUrl() — scheme / credentials / host / resolved-IP check
 *      before we even build the request.
 *   2. NoPrivateNetworkHttpClient — rejects the connection if the address the
 *      transport actually dials resolves into a private / reserved range. This is
 *      the rebinding-proof check: it runs at connect time, not at guard time.
 *
 * Redirects are disabled so a 30x cannot bounce the request to an internal host.
 */
final class OutboundWebhookHttpClient
{
    public const TIMEOUT_SECONDS = 5;
    public const MAX_RESPONSE_BYTES = 2048;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
        HttpClientInterface $httpClient,
    ) {
        $this->httpClient = new NoPrivateNetworkHttpClient($httpClient);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers): array
    {
        $this->ssrfGuard->assertSafeUrl($url);

        $safeHeaders = ['Content-Type' => 'application/json', 'User-Agent' => 'CPalius-Webhook/1.0'];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) === 1) {
                $safeHeaders[$name] = str_replace(["\r", "\n"], '', $value);
            }
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $safeHeaders,
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
            ]);

            $status = $response->getStatusCode();
            $content = substr($response->getContent(false), 0, self::MAX_RESPONSE_BYTES);
        } catch (TransportException $e) {
            throw new \RuntimeException('Webhook delivery failed: '.$e->getMessage(), 0, $e);
        }

        return ['status' => $status, 'body' => $content];
    }
}
