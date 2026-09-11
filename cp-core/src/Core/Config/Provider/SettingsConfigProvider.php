<?php

declare(strict_types=1);

namespace App\Core\Config\Provider;

use App\Core\Config\ConfigProviderInterface;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exports cp_settings rows that have a #[CpSetting] definition. Translatable
 * values become a { locale: text } map for readability. Import is upsert-only
 * (never deletes a row — local rows may be deliberate overrides).
 */
final class SettingsConfigProvider implements ConfigProviderInterface
{
    private const DOCUMENT = 'settings';

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function documents(): array
    {
        return [self::DOCUMENT];
    }

    public function ownsDocument(string $name): bool
    {
        return $name === self::DOCUMENT;
    }

    public function exportDocument(string $name): array
    {
        $definitions = $this->definitionMap();
        $stored = $this->repository->findAllAsMap();

        $out = [];
        foreach ($stored as $key => $raw) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $out[$key] = $this->decode($raw, $definitions[$key]->isTranslatable());
        }

        return $out;
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $definitions = $this->definitionMap();
        $stored = $this->repository->findAllAsMap();
        $changes = [];

        foreach ($incoming as $key => $value) {
            if (!\is_string($key) || !isset($definitions[$key])) {
                continue;
            }
            $translatable = $definitions[$key]->isTranslatable();
            if (!\array_key_exists($key, $stored)) {
                $changes[] = sprintf('+ %s', $key);
            } elseif (!$this->sameValue($stored[$key], $value, $translatable)) {
                $changes[] = sprintf('~ %s', $key);
            }
        }

        return $changes;
    }

    public function importDocument(string $name, array $incoming): array
    {
        $definitions = $this->definitionMap();
        $existing = $this->repository->findIndexedByKeys(array_keys($incoming));
        $applied = [];

        foreach ($incoming as $key => $value) {
            if (!\is_string($key) || !isset($definitions[$key])) {
                continue;
            }
            $translatable = $definitions[$key]->isTranslatable();
            $row = $existing[$key] ?? null;

            if ($row instanceof Setting && $this->sameValue($row->getSettingValue(), $value, $translatable)) {
                continue;
            }

            if (!$row instanceof Setting) {
                $row = new Setting($key, $definitions[$key]->module);
                $this->entityManager->persist($row);
            }
            $row->setSettingValue($this->encode($value, $translatable));
            $applied[] = $key;
        }

        return $applied;
    }

    private function sameValue(?string $stored, mixed $incoming, bool $translatable): bool
    {
        if ($translatable) {
            return $this->localeMap($stored) === $this->localeMap($this->encode($incoming, true));
        }

        return (string) $stored === $this->encode($incoming, false);
    }

    /**
     * @return array<string, string>
     */
    private function localeMap(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['*' => $raw];
        }
        if (!\is_array($decoded)) {
            return ['*' => $raw];
        }

        $map = [];
        foreach ($decoded as $locale => $text) {
            if (\is_string($locale)) {
                $map[$locale] = (string) $text;
            }
        }
        ksort($map);

        return $map;
    }

    public function afterImport(): void
    {
        $this->settings->clearCache();
    }

    /**
     * @return array<string, \App\Core\Settings\SettingDefinition>
     */
    private function definitionMap(): array
    {
        $map = [];
        foreach ($this->settings->all() as $definition) {
            $map[$definition->key] = $definition;
        }

        return $map;
    }

    private function decode(?string $raw, bool $translatable): mixed
    {
        if ($raw === null) {
            return null;
        }
        if ($translatable && str_starts_with(ltrim($raw), '{')) {
            try {
                $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);

                return \is_array($decoded) ? $decoded : $raw;
            } catch (\JsonException) {
                return $raw;
            }
        }

        return $raw;
    }

    private function encode(mixed $value, bool $translatable): string
    {
        if ($translatable && \is_array($value)) {
            $clean = [];
            foreach ($value as $locale => $text) {
                if (\is_string($locale) && (\is_string($text) || is_numeric($text))) {
                    $clean[$locale] = (string) $text;
                }
            }

            return json_encode($clean, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return match (true) {
            \is_bool($value) => $value ? '1' : '0',
            $value === null => '',
            default => (string) $value,
        };
    }
}
