<?php

declare(strict_types=1);

namespace Modules\Pages\Field;

use App\Core\Content\RichTextSanitizer;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Law 5.3 allowlist + sanitization for per-page custom fields.
 */
final class PageFieldNormalizer
{
    public const MAX_FIELDS = 40;

    public const NODE_TYPE_FIELD_GROUP = 'page_field_group';

    public function __construct(
        private readonly RichTextSanitizer $richTextSanitizer,
    ) {
    }

    /**
     * @param list<mixed> $raw
     *
     * @return list<array{id: string, key: string, type: string, label: string, required: bool, choices: list<string>, value: mixed}>
     */
    public function normalizeList(array $raw, bool $includeValues = true): array
    {
        $out = [];
        $usedKeys = [];

        foreach (\array_slice($raw, 0, self::MAX_FIELDS) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeOne($row, $includeValues, $usedKeys);
            if ($normalized === null) {
                continue;
            }

            $usedKeys[$normalized['key']] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, true>  $usedKeys
     *
     * @return array{id: string, key: string, type: string, label: string, required: bool, choices: list<string>, value: mixed}|null
     */
    private function normalizeOne(array $row, bool $includeValues, array $usedKeys): ?array
    {
        $type = (string) ($row['type'] ?? PageFieldType::TEXT);
        if (!PageFieldType::isValid($type)) {
            $type = PageFieldType::TEXT;
        }

        $label = trim(strip_tags((string) ($row['label'] ?? '')));
        if ($label === '') {
            return null;
        }
        $label = mb_substr($label, 0, 120);

        $key = $this->normalizeKey((string) ($row['key'] ?? ''), $label);
        if ($key === '') {
            $key = 'field';
        }
        if (isset($usedKeys[$key])) {
            $suffix = 2;
            $base = $key;
            while (isset($usedKeys[$base.'_'.$suffix])) {
                ++$suffix;
            }
            $key = $base.'_'.$suffix;
        }

        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($row['id'] ?? '')) ?: bin2hex(random_bytes(8));
        $required = !empty($row['required']);
        $choices = $this->normalizeChoices($row['choices'] ?? []);

        $value = $includeValues ? $this->normalizeValue($type, $row['value'] ?? null, $choices) : null;

        return [
            'id' => mb_substr($id, 0, 64),
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'choices' => $choices,
            'value' => $value,
        ];
    }

    /**
     * @param list<string>|string $raw
     *
     * @return list<string>
     */
    private function normalizeChoices(array|string $raw): array
    {
        if (\is_string($raw)) {
            $parts = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
        } else {
            $parts = $raw;
        }

        $out = [];
        foreach ($parts as $part) {
            $choice = trim(strip_tags((string) $part));
            if ($choice === '') {
                continue;
            }
            $out[] = mb_substr($choice, 0, 120);
            if (\count($out) >= 50) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $choices
     */
    private function normalizeValue(string $type, mixed $raw, array $choices): mixed
    {
        return match ($type) {
            PageFieldType::WYSIWYG => $this->richTextSanitizer->sanitize(is_string($raw) ? $raw : ''),
            PageFieldType::TEXTAREA => mb_substr(trim(strip_tags((string) $raw)), 0, 20000),
            PageFieldType::TEXT => mb_substr(trim(strip_tags((string) $raw)), 0, 500),
            PageFieldType::URL => $this->normalizeUrl($raw),
            PageFieldType::EMAIL => $this->normalizeEmail($raw),
            PageFieldType::NUMBER => $this->normalizeNumber($raw),
            PageFieldType::CHECKBOX => !\in_array($raw, [0, '0', false, null, ''], true),
            PageFieldType::SELECT => $this->normalizeSelect($raw, $choices),
            PageFieldType::DATE => $this->normalizeDate($raw),
            PageFieldType::IMAGE => $this->normalizeAssetId($raw),
            PageFieldType::GALLERY => $this->normalizeGallery($raw),
            default => mb_substr(trim(strip_tags((string) $raw)), 0, 500),
        };
    }

    private function normalizeKey(string $key, string $label): string
    {
        $source = trim($key) !== '' ? $key : $label;
        $slugger = new AsciiSlugger('en');
        $slug = strtolower($slugger->slug($source)->toString());
        $slug = str_replace('-', '_', $slug);

        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $slug)) {
            return $slug !== '' && preg_match('/^[a-z0-9_]+$/', $slug) ? 'f_'.$slug : '';
        }

        return $slug;
    }

    private function normalizeUrl(mixed $raw): ?string
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return null;
        }
        if (filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($url, 0, 500);
    }

    private function normalizeEmail(mixed $raw): ?string
    {
        $email = trim((string) $raw);
        if ($email === '') {
            return null;
        }

        return filter_var($email, \FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 180) : null;
    }

    private function normalizeNumber(mixed $raw): int|float|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return $raw;
        }
        if (!is_numeric($raw)) {
            return null;
        }

        return str_contains((string) $raw, '.') ? (float) $raw : (int) $raw;
    }

    /**
     * @param list<string> $choices
     */
    private function normalizeSelect(mixed $raw, array $choices): ?string
    {
        $value = trim(strip_tags((string) $raw));
        if ($value === '' || ($choices !== [] && !\in_array($value, $choices, true))) {
            return null;
        }

        return mb_substr($value, 0, 120);
    }

    private function normalizeDate(mixed $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $dt instanceof \DateTimeImmutable ? $dt->format('Y-m-d') : null;
    }

    private function normalizeAssetId(mixed $raw): ?int
    {
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function normalizeGallery(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $item) {
            $id = $this->normalizeAssetId($item);
            if ($id !== null) {
                $ids[] = $id;
            }
            if (\count($ids) >= 24) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    public function sanitizeCss(string $css): string
    {
        $css = str_replace(["\0", '</'], '', $css);
        $css = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $css) ?? $css;
        $css = preg_replace('/expression\s*\(/i', '', $css) ?? $css;
        $css = preg_replace('/javascript\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/-moz-binding/i', '', $css) ?? $css;
        $css = preg_replace('/behavior\s*:/i', '', $css) ?? $css;

        return mb_substr(trim($css), 0, 20000);
    }

    public function sanitizeJs(string $js): string
    {
        $js = str_replace(["\0", '</script', '</SCRIPT'], '', $js);

        return mb_substr(trim($js), 0, 20000);
    }
}
