<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;
use App\Entity\Asset;
use App\Repository\AssetRepository;

/**
 * Stores a Media asset id that must resolve to an image/* asset.
 */
#[CpFieldType]
final class ImageFieldType extends AbstractFieldType
{
    public function __construct(
        private readonly AssetRepository $assets,
    ) {
    }

    public static function id(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'field.type.image';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        if (!\is_int($value)) {
            return [];
        }

        $asset = $this->assets->find($value);
        if (!$asset instanceof Asset) {
            return ['field.violation.asset_missing'];
        }

        return str_starts_with($asset->getMimeType(), 'image/') ? [] : ['field.violation.asset_not_image'];
    }

    public function indexKind(): ?string
    {
        return 'int';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return \is_int($value) ? $value : null;
    }
}
