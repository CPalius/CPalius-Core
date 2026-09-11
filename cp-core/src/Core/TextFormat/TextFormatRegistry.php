<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

use App\Core\TextFormat\Entity\TextFormat;
use App\Core\TextFormat\Repository\TextFormatRepository;

/**
 * Compile-time catalog (core text_formats.yaml + per-module files) merged with
 * optional DB overrides from AACP. YAML is the seed; a DB row for the same id
 * wins for filters/label so `cp:config export` captures operator edits.
 *
 * `basic_html` always exists (registry guarantee) — every rich_text write
 * falls back to it, same way ViewModeRegistry guarantees `default`.
 */
final class TextFormatRegistry
{
    public const BASIC_HTML = 'basic_html';
    public const RESTRICTED = 'restricted';
    public const FULL_HTML = 'full_html';
    public const MARKDOWN = 'markdown';

    public const ID_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** @var array<string, array<string, mixed>> id => spec */
    private array $catalog = [];

    /** @var array<string, ResolvedTextFormat>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly ?TextFormatRepository $repository = null,
    ) {
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function register(string $id, array $spec): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }

        $this->catalog[$id] = $spec;
        $this->resolved = null;
    }

    public function invalidate(): void
    {
        $this->resolved = null;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): ?ResolvedTextFormat
    {
        return $this->all()[$id] ?? null;
    }

    public function getOrFallback(string $id): ResolvedTextFormat
    {
        return $this->get($id) ?? $this->get(self::BASIC_HTML) ?? $this->syntheticBasicHtml();
    }

    /**
     * @return array<string, ResolvedTextFormat>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $out = [];
        foreach ($this->catalog as $id => $spec) {
            $out[$id] = $this->resolve($id, $spec, null);
        }

        if ($this->repository !== null) {
            foreach ($this->repository->findAllOrdered() as $row) {
                $id = $row->getMachineName();
                $base = $this->catalog[$id] ?? [];
                $out[$id] = $this->resolve($id, $base, $row);
            }
        }

        if (!isset($out[self::BASIC_HTML])) {
            $out[self::BASIC_HTML] = $this->syntheticBasicHtml();
        }

        return $this->resolved = $out;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, string> id => label translation key
     */
    public function choices(): array
    {
        $out = [];
        foreach ($this->all() as $id => $format) {
            $out[$id] = $format->label;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function resolve(string $id, array $spec, ?TextFormat $row): ResolvedTextFormat
    {
        $filters = $row !== null ? $row->getFilters() : $this->normalizeFilters($spec['filters'] ?? []);
        if ($filters === []) {
            $filters = $this->normalizeFilters($spec['filters'] ?? []);
        }

        return new ResolvedTextFormat(
            id: $id,
            label: $row?->getLabel() ?: (string) ($spec['label'] ?? $id),
            description: $row?->getDescription() ?: (string) ($spec['description'] ?? ''),
            wysiwyg: $row !== null ? $row->isWysiwyg() : (bool) ($spec['wysiwyg'] ?? false),
            filters: $filters,
        );
    }

    /**
     * @param mixed $raw
     *
     * @return list<array{id: string, enabled: bool, weight: int, settings: array<string, mixed>}>
     */
    public function normalizeFilters(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $i => $row) {
            if (!\is_array($row) || !\is_string($row['id'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $row['id']) !== 1) {
                continue;
            }
            $settings = $row['settings'] ?? [];
            $out[] = [
                'id' => (string) $row['id'],
                'enabled' => (bool) ($row['enabled'] ?? true),
                'weight' => (int) ($row['weight'] ?? $i),
                'settings' => \is_array($settings) ? $settings : [],
            ];
        }

        return $out;
    }

    private function syntheticBasicHtml(): ResolvedTextFormat
    {
        return new ResolvedTextFormat(
            id: self::BASIC_HTML,
            label: 'text_format.label.basic_html',
            description: '',
            wysiwyg: true,
            filters: $this->normalizeFilters([
                ['id' => 'html_restrict', 'enabled' => true, 'weight' => 0, 'settings' => [
                    'allow_elements' => ['p' => [], 'br' => [], 'strong' => [], 'em' => [], 'a' => ['href', 'title']],
                ]],
            ]),
        );
    }
}
