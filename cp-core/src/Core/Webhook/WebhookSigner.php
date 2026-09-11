<?php

declare(strict_types=1);

namespace App\Core\Webhook;

/**
 * Stable outbound envelope + HMAC. Timestamp is unix seconds.
 */
final class WebhookSigner
{
    public const HEADER = 'X-CP-Webhook-Signature';
    public const MAX_SKEW_SECONDS = 300;

    /**
     * @param array<string, mixed> $envelope
     */
    public function sign(array $envelope, string $secret, int $timestamp): string
    {
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function verify(string $header, string $rawBody, string $secret, int $now): bool
    {
        if (\strlen($header) > 128 || preg_match('/^t=(\d{1,20}),v1=([a-f0-9]{64})$/', $header, $m) !== 1) {
            return false;
        }

        $timestamp = (int) $m[1];
        if (abs($now - $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $m[2]);
    }
}
