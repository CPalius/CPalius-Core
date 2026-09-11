<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

/**
 * Value from a fixed allowlist. Choices are configured per field as "value|label"
 * lines in the "choices" setting.
 */
#[CpFieldType]
final class SelectFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return 'field.type.select';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        $value = trim(strip_tags((string) $raw));
        if ($value === '') {
            return null;
        }

        $choices = $this->choices($context);

        return $choices === [] || \array_key_exists($value, $choices) ? mb_substr($value, 0, 191) : null;
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        $choices = $this->choices($context);

        return \is_string($value) && $choices !== [] && !\array_key_exists($value, $choices)
            ? ['field.violation.invalid_choice']
            : [];
    }

    public function indexKind(): ?string
    {
        return 'string';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return \is_string($value) ? mb_substr($value, 0, 255) : null;
    }

    public function settingsSchema(): array
    {
        return [
            ['name' => 'choices', 'type' => 'textarea', 'label' => 'field.setting.choices', 'default' => '', 'help' => 'field.setting.choices_help'],
        ];
    }

    public function normalizeSettings(array $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) ($raw['choices'] ?? '')) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(strip_tags($line));
            if ($line === '') {
                continue;
            }
            $clean[] = mb_substr($line, 0, 191);
            if (\count($clean) >= 200) {
                break;
            }
        }

        return ['choices' => implode("\n", array_values(array_unique($clean)))];
    }

    /**
     * @return array<string, string> value => label
     */
    public function choicesFor(FieldContext $context): array
    {
        return $this->choices($context);
    }

    /**
     * @return array<string, string>
     */
    private function choices(FieldContext $context): array
    {
        $raw = (string) $context->definition->getSetting('choices', '');
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$value, $label] = array_pad(explode('|', $line, 2), 2, null);
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $out[$value] = trim((string) ($label ?? $value)) ?: $value;
        }

        return $out;
    }
}
