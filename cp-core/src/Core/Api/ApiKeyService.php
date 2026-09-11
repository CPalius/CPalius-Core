<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * API keys stored as one JSON list in cp_settings (core.api_keys), not as #[CpSetting] scalars.
 * Only SHA-256 hashes and last-4 are persisted; plaintext is returned once from generate().
 */
final class ApiKeyService
{
    private const SETTING_KEY = 'core.api_keys';
    private const KEY_PREFIX = 'cpk_';

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiCapabilityPolicy $capabilityPolicy = new ApiCapabilityPolicy(),
        private readonly ApiClientIpPolicy $ipPolicy = new ApiClientIpPolicy(),
    ) {
    }

    /**
     * @param list<string> $capabilities
     * @param list<string> $ipAllowlist
     *
     * @return array{key: string, apiKey: ApiKey}
     */
    public function generate(
        string $label,
        array $capabilities = [],
        ?string $tenantId = null,
        array $ipAllowlist = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $rawKey = self::KEY_PREFIX.bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawKey);

        $apiKey = new ApiKey(
            id: bin2hex(random_bytes(16)),
            label: $label,
            hash: $hash,
            lastFourChars: substr($rawKey, -4),
            createdAt: new \DateTimeImmutable(),
            active: true,
            capabilities: $this->capabilityPolicy->sanitizeGrants($capabilities),
            tenantId: $tenantId !== null && $tenantId !== '' ? $tenantId : null,
            ipAllowlist: $this->sanitizeIpAllowlist($ipAllowlist),
            expiresAt: $expiresAt,
        );

        $keys = $this->loadAll();
        $keys[] = $apiKey;
        $this->persistAll($keys);

        return ['key' => $rawKey, 'apiKey' => $apiKey];
    }

    /**
     * @return list<ApiKey>
     */
    public function findAll(): array
    {
        return $this->loadAll();
    }

    public function delete(string $id): bool
    {
        $keys = $this->loadAll();
        $filtered = array_values(array_filter($keys, static fn (ApiKey $k): bool => $k->id !== $id));

        if (count($filtered) === count($keys)) {
            return false;
        }

        $this->persistAll($filtered);

        return true;
    }

    public function toggleActive(string $id): ?ApiKey
    {
        $keys = $this->loadAll();
        $updated = null;

        foreach ($keys as $index => $key) {
            if ($key->id === $id) {
                $updated = $key->withActive(!$key->active);
                $keys[$index] = $updated;
                break;
            }
        }

        if ($updated === null) {
            return null;
        }

        $this->persistAll($keys);

        return $updated;
    }

    /**
     * Compare the provided key against active hashes with hash_equals() (timing-safe).
     */
    public function isValid(string $providedKey): bool
    {
        return $this->matchActiveKey($providedKey) !== null;
    }

    /**
     * Full machine-identity check: hash, active, expiry, IP allowlist. Never returns a revoked key.
     */
    public function authenticate(Request $request): ?ApiKey
    {
        $providedKey = (string) $request->headers->get('X-CP-API-KEY', '');
        $matched = $this->matchActiveKey($providedKey);
        if ($matched === null) {
            return null;
        }

        if ($matched->isExpired()) {
            return null;
        }

        $clientIp = (string) $request->getClientIp();
        if (!$this->ipPolicy->allows($clientIp, $matched->ipAllowlist)) {
            return null;
        }

        return $matched;
    }

    private function matchActiveKey(string $providedKey): ?ApiKey
    {
        if ($providedKey === '') {
            return null;
        }

        $providedHash = hash('sha256', $providedKey);

        foreach ($this->loadAll() as $apiKey) {
            if (!$apiKey->active) {
                continue;
            }

            if (hash_equals($apiKey->hash, $providedHash)) {
                return $apiKey;
            }
        }

        return null;
    }

    /**
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private function sanitizeIpAllowlist(array $entries): array
    {
        $clean = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '' || \strlen($entry) > 64) {
                continue;
            }
            if (preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $entry) !== 1) {
                continue;
            }
            $clean[] = $entry;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @return list<ApiKey>
     */
    private function loadAll(): array
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => self::SETTING_KEY]);
        $raw = $setting?->getSettingValue();

        if ($raw === null || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $keys = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                try {
                    $keys[] = ApiKey::fromArray($item);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $keys;
    }

    /**
     * @param list<ApiKey> $keys
     */
    private function persistAll(array $keys): void
    {
        $payload = json_encode(
            array_map(static fn (ApiKey $k): array => $k->toArray(), $keys),
            JSON_THROW_ON_ERROR,
        );

        $setting = $this->settingRepository->findOneBy(['settingKey' => self::SETTING_KEY]);

        if ($setting === null) {
            $setting = new Setting(self::SETTING_KEY, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($payload);
        $this->entityManager->flush();
    }
}
