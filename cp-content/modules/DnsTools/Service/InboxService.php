<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;
use Modules\DnsTools\Security\QueryValidator;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Opens a one-shot catch-all address, accepts inbound MIME, or scores a pasted source.
 * Mail is never sent.
 */
final class InboxService
{
    public function __construct(
        private readonly InboxStore $store,
        private readonly SpamScoreService $scorer,
        private readonly SettingsRegistry $settings,
        private readonly QueryValidator $validator,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input, Request $request): array
    {
        $raw = trim((string) ($input['q'] ?? ''));
        $action = trim((string) ($input['action'] ?? ''));

        if ($raw !== '' && ($action === '' || $action === 'score')) {
            return $this->scorer->score($raw);
        }

        return match ($action) {
            'poll' => $this->poll((string) ($input['token'] ?? '')),
            default => $this->open($request),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function ingest(string $to, string $raw): array
    {
        $token = $this->tokenFromAddress($to);
        if ($token === null) {
            return ['ok' => false];
        }

        $row = $this->store->get($token);
        if ($row === null || $row['status'] !== 'waiting') {
            return ['ok' => false];
        }

        $expected = strtolower($row['address']);
        if (!$this->addressMatches($to, $expected)) {
            return ['ok' => false];
        }

        $result = $this->scorer->score($raw);
        $result['address'] = $row['address'];
        $this->store->markScored($token, $result);

        return ['ok' => true, 'token' => $token];
    }

    /**
     * @return array<string, mixed>
     */
    public function open(Request $request): array
    {
        $domain = $this->inboxDomain();
        if ($domain === null) {
            return [
                'status' => 'paste_only',
                'inbox_ready' => false,
                'note' => 'inbox_not_configured',
            ];
        }

        if (!$this->allowOpen($request)) {
            throw new \RuntimeException('dnstools.error.rate_limit');
        }

        $ttl = max(300, min(3600, (int) $this->settings->get('dnstools.mail_inbox_ttl', 1200)));
        $row = $this->store->create($domain, $ttl);

        return $this->waiting($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function poll(string $token): array
    {
        $row = $this->store->get($token);
        if ($row === null) {
            throw new \InvalidArgumentException('dnstools.error.inbox_unknown');
        }

        if ($row['status'] === 'scored' && \is_array($row['result'])) {
            return $row['result'] + ['address' => $row['address'], 'inbox_ready' => true];
        }

        if ($row['status'] === 'expired') {
            return [
                'status' => 'expired',
                'address' => $row['address'],
                'inbox_ready' => true,
                'expires_in' => 0,
            ];
        }

        return $this->waiting($row);
    }

    public function inboxDomain(): ?string
    {
        if ((string) $this->settings->get('dnstools.mail_inbox_enabled', '0') !== '1') {
            return null;
        }

        $domain = $this->validator->domain((string) $this->settings->get('dnstools.mail_inbox_domain', ''));

        return $domain;
    }

    public function tokenFromAddress(string $address): ?string
    {
        if (preg_match('/\b(t[a-f0-9]{16})@/i', $address, $m) !== 1) {
            return null;
        }

        $token = strtolower($m[1]);

        return $this->store->validToken($token) ? $token : null;
    }

    /**
     * @param array{token: string, address: string, status: string, created_at: int, expires_at: int, result: ?array<string, mixed>} $row
     * @return array<string, mixed>
     */
    private function waiting(array $row): array
    {
        return [
            'status' => 'waiting',
            'token' => $row['token'],
            'address' => $row['address'],
            'inbox_ready' => true,
            'expires_in' => max(0, (int) $row['expires_at'] - time()),
        ];
    }

    private function addressMatches(string $to, string $expected): bool
    {
        return str_contains(strtolower($to), $expected);
    }

    private function allowOpen(Request $request): bool
    {
        $identity = hash('sha256', (string) $request->getClientIp());
        $slot = (string) intdiv(time(), 3600);
        $key = 'dnstools_inbox_open_'.$identity.'_'.$slot;

        try {
            $item = $this->cache->getItem($key);
            $count = $item->isHit() ? (int) $item->get() : 0;
            if ($count >= 10) {
                return false;
            }
            $item->set($count + 1);
            $item->expiresAfter(3660);
            $this->cache->save($item);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
