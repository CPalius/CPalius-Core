<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

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
    ) {
    }

    /**
     * Create a random key, persist its hash, and return the plaintext once.
     *
     * @return array{key: string, apiKey: ApiKey}
     */
    public function generate(string $label): array
    {
        $rawKey = self::KEY_PREFIX.bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawKey);

        $apiKey = new ApiKey(
            id: bin2hex(random_bytes(16)),
            label: $label,
            hash: $hash,
            lastFourChars: substr($rawKey, -4),
            createdAt: new \DateTimeImmutable(),
            active: true,
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
        if ($providedKey === '') {
            return false;
        }

        $providedHash = hash('sha256', $providedKey);

        foreach ($this->loadAll() as $apiKey) {
            if (!$apiKey->active) {
                continue;
            }

            if (hash_equals($apiKey->hash, $providedHash)) {
                return true;
            }
        }

        return false;
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
