<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * In-memory API-key DTO hydrated from cp_settings JSON — not a Doctrine entity.
 * Plaintext is never stored; capability "*" is rejected at write time.
 */
final class ApiKey
{
    public const MAX_CAPABILITIES = 32;

    /**
     * @param list<string> $capabilities
     * @param list<string> $ipAllowlist
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $hash,
        public readonly string $lastFourChars,
        public readonly \DateTimeImmutable $createdAt,
        public readonly bool $active,
        public readonly array $capabilities = [],
        public readonly ?string $tenantId = null,
        public readonly array $ipAllowlist = [],
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     hash: string,
     *     lastFourChars: string,
     *     createdAt: string,
     *     active: bool,
     *     capabilities: list<string>,
     *     tenantId: ?string,
     *     ipAllowlist: list<string>,
     *     expiresAt: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'hash' => $this->hash,
            'lastFourChars' => $this->lastFourChars,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'active' => $this->active,
            'capabilities' => $this->capabilities,
            'tenantId' => $this->tenantId,
            'ipAllowlist' => $this->ipAllowlist,
            'expiresAt' => $this->expiresAt?->format(\DATE_ATOM),
        ];
    }

    /**
     * Safe for AACP JSON — never includes the hash.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $data = $this->toArray();
        unset($data['hash']);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expiresRaw = $data['expiresAt'] ?? null;
        $tenantRaw = $data['tenantId'] ?? null;

        return new self(
            id: (string) $data['id'],
            label: (string) $data['label'],
            hash: (string) $data['hash'],
            lastFourChars: (string) $data['lastFourChars'],
            createdAt: new \DateTimeImmutable((string) $data['createdAt']),
            active: (bool) $data['active'],
            capabilities: self::normalizeStringList($data['capabilities'] ?? []),
            tenantId: \is_string($tenantRaw) && $tenantRaw !== '' ? $tenantRaw : null,
            ipAllowlist: self::normalizeStringList($data['ipAllowlist'] ?? []),
            expiresAt: \is_string($expiresRaw) && $expiresRaw !== '' ? new \DateTimeImmutable($expiresRaw) : null,
        );
    }

    public function withActive(bool $active): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->hash,
            $this->lastFourChars,
            $this->createdAt,
            $active,
            $this->capabilities,
            $this->tenantId,
            $this->ipAllowlist,
            $this->expiresAt,
        );
    }

    public function hasCapability(string $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (\is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }
}
