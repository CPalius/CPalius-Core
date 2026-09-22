<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Short-lived test inboxes. The local-part is the secret; raw mail is never kept.
 */
final class InboxStore
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{token: string, address: string, status: string, created_at: int, expires_at: int, result: ?array<string, mixed>}
     */
    public function create(string $domain, int $ttl): array
    {
        $token = 't'.bin2hex(random_bytes(8));
        $row = [
            'token' => $token,
            'address' => $token.'@'.$domain,
            'status' => 'waiting',
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'result' => null,
        ];
        $this->save($row, $ttl + 60);

        return $row;
    }

    /**
     * @return array{token: string, address: string, status: string, created_at: int, expires_at: int, result: ?array<string, mixed>}|null
     */
    public function get(string $token): ?array
    {
        if (!$this->validToken($token)) {
            return null;
        }

        try {
            $item = $this->cache->getItem($this->key($token));
        } catch (\Throwable) {
            return null;
        }

        if (!$item->isHit() || !\is_array($item->get())) {
            return null;
        }

        /** @var array{token: string, address: string, status: string, created_at: int, expires_at: int, result: ?array<string, mixed>} $row */
        $row = $item->get();
        if ((int) $row['expires_at'] <= time() && $row['status'] === 'waiting') {
            $row['status'] = 'expired';
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markScored(string $token, array $result): void
    {
        $row = $this->get($token);
        if ($row === null || $row['status'] === 'expired') {
            return;
        }

        $row['status'] = 'scored';
        $row['result'] = $result;
        $ttl = max(30, (int) $row['expires_at'] - time());
        $this->save($row, $ttl + 60);
    }

    public function validToken(string $token): bool
    {
        return preg_match('/^t[a-f0-9]{16}$/', $token) === 1;
    }

    /**
     * @param array{token: string, address: string, status: string, created_at: int, expires_at: int, result: ?array<string, mixed>} $row
     */
    private function save(array $row, int $ttl): void
    {
        try {
            $item = $this->cache->getItem($this->key($row['token']));
            $item->set($row);
            $item->expiresAfter(max(30, $ttl));
            $this->cache->save($item);
        } catch (\Throwable) {
        }
    }

    private function key(string $token): string
    {
        return 'dnstools_inbox_'.$token;
    }
}
