<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Runtime value store for #[CpSetting] definitions (Cotonti-style settings engine).
 * Not a #[CpResource]; guarded by system.settings.manage only.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'cp_settings')]
#[ORM\UniqueConstraint(name: 'uniq_setting_key', columns: ['setting_key'])]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', type: 'string', length: 191)]
    private string $settingKey;

    #[ORM\Column(name: 'setting_value', type: 'text', nullable: true)]
    private ?string $settingValue = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $module = 'core';

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $settingKey, string $module = 'core')
    {
        $this->settingKey = $settingKey;
        $this->module = $module;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getSettingValue(): ?string
    {
        return $this->settingValue;
    }

    public function setSettingValue(?string $settingValue): static
    {
        $this->settingValue = $settingValue;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
