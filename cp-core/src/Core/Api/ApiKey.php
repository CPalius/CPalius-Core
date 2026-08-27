<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * Tek bir API anahtarının çalışma zamanı (in-memory) temsili. ApiKeyService
 * tarafından cp_settings.setting_value (JSON) alanından hydrate edilir/
 * dehydrate edilir — bu sınıfın KENDİSİ Doctrine entity'si DEĞİLDİR,
 * ekstra tablo şişkinliği yaratmamak için bilinçli olarak salt bir DTO'dur
 * (bkz. ApiKeyService docblock'u).
 */
final class ApiKey
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $hash,
        public readonly string $lastFourChars,
        public readonly \DateTimeImmutable $createdAt,
        public readonly bool $active,
    ) {
    }

    /**
     * @return array{id: string, label: string, hash: string, lastFourChars: string, createdAt: string, active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'hash' => $this->hash,
            'lastFourChars' => $this->lastFourChars,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'active' => $this->active,
        ];
    }

    /**
     * @param array{id: string, label: string, hash: string, lastFourChars: string, createdAt: string, active: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            label: $data['label'],
            hash: $data['hash'],
            lastFourChars: $data['lastFourChars'],
            createdAt: new \DateTimeImmutable($data['createdAt']),
            active: $data['active'],
        );
    }

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->label, $this->hash, $this->lastFourChars, $this->createdAt, $active);
    }
}
