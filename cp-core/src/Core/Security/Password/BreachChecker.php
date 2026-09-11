<?php

declare(strict_types=1);

namespace App\Core\Security\Password;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks a password against the Pwned Passwords corpus using k-anonymity: only
 * the first five characters of the SHA-1 hash leave the server, so the remote
 * service never sees enough to identify the password.
 *
 * Prefix responses are cached because a prefix bucket is not a secret and the
 * same buckets recur constantly across a user base.
 */
final class BreachChecker
{
    private const ENDPOINT = 'https://api.pwnedpasswords.com/range/';
    private const CACHE_TTL = 86400;
    private const TIMEOUT = 3.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return int|null Times the password appears in known breaches, or null when
     *   the corpus could not be consulted. Callers decide whether an unknown
     *   result blocks (fail closed) or passes (fail open).
     */
    public function occurrences(string $password): ?int
    {
        if ($password === '') {
            return null;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $bucket = $this->bucket($prefix);
        if ($bucket === null) {
            return null;
        }

        return $bucket[$suffix] ?? 0;
    }

    /**
     * @return array<string, int>|null Suffix => breach count for one hash prefix.
     */
    private function bucket(string $prefix): ?array
    {
        $cacheKey = 'cp_hibp_'.$prefix;

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                $cached = $item->get();

                return \is_array($cached) ? $cached : null;
            }
        } catch (\Throwable) {
            $item = null;
        }

        $body = $this->fetch($prefix);
        if ($body === null) {
            return null;
        }

        $bucket = $this->parse($body);

        if ($item !== null) {
            try {
                $item->set($bucket);
                $item->expiresAfter(self::CACHE_TTL);
                $this->cache->save($item);
            } catch (\Throwable) {
                // A cache miss next time is the only consequence.
            }
        }

        return $bucket;
    }

    private function fetch(string $prefix): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT.$prefix, [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'headers' => [
                    // Pads the response to a uniform size so the body length cannot
                    // hint at how many hashes share the prefix.
                    'Add-Padding' => 'true',
                    'User-Agent' => 'CPalius-CMF',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function parse(string $body): array
    {
        $bucket = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$suffix, $count] = explode(':', $line, 2);
            $count = (int) $count;

            // Padding entries are returned with a count of 0 and must not be
            // mistaken for a real (zero-occurrence) hit.
            if ($count > 0) {
                $bucket[strtoupper(trim($suffix))] = $count;
            }
        }

        return $bucket;
    }
}
