<?php

declare(strict_types=1);

namespace App\Core\Field\Display;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldContext;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Type\SelectFieldType;
use App\Core\Media\Twig\ImageThumbnailRuntime;
use App\Core\TextFormat\TextFormatProcessor;
use App\Core\TextFormat\TextFormatRegistry;
use App\Core\Token\TokenContext;
use App\Entity\Asset;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\AssetRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns one stored field value into safe display HTML. rich_text runs the
 * named text-format pipeline on output (T2.5); everything else is escaped here.
 */
final class FieldFormatterResolver
{
    public function __construct(
        private readonly FieldTypeRegistry $types,
        private readonly ReferenceBatchLoader $references,
        private readonly ImageThumbnailRuntime $thumbnails,
        private readonly AssetRepository $assets,
        private readonly TranslatorInterface $translator,
        private readonly TextFormatProcessor $textFormats,
    ) {
    }

    public function format(FieldDefinition $definition, mixed $value, string $locale, mixed $entity = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($definition->getType()) {
            'rich_text' => $this->richText($value, $entity),
            'textarea' => nl2br($this->e((string) $value)),
            'boolean' => $this->e($this->translator->trans($value ? 'field.value.yes' : 'field.value.no', [], null, $locale)),
            'url' => sprintf('<a href="%s" rel="nofollow noopener" target="_blank">%1$s</a>', $this->e((string) $value)),
            'email' => sprintf('<a href="mailto:%s">%1$s</a>', $this->e((string) $value)),
            'date' => $this->e($this->formatDate((string) $value, false)),
            'datetime' => $this->e($this->formatDate((string) $value, true)),
            'select' => $this->e($this->selectLabel($definition, (string) $value, $locale)),
            'reference' => $this->reference($definition, (int) $value),
            'image' => $this->image((int) $value),
            'file' => $this->file((int) $value),
            default => $this->e((string) $value),
        };
    }

    private function richText(mixed $value, mixed $entity): string
    {
        [$html, $format] = \App\Core\Field\Type\RichTextFieldType::extract($value);
        if (trim($html) === '') {
            return '';
        }

        return $this->textFormats->processForDisplay(
            $html,
            $format !== '' ? $format : TextFormatRegistry::BASIC_HTML,
            TokenContext::for($entity),
        );
    }

    private function reference(FieldDefinition $definition, int $id): string
    {
        $target = (string) $definition->getSetting('target', 'node');
        $entity = $this->references->get($target, $id);
        if ($entity === null) {
            return '';
        }

        if ($entity instanceof Node) {
            return $this->e($entity->getTitle());
        }
        if ($entity instanceof User) {
            return $this->e($entity->getUsername() ?? $entity->getEmail());
        }
        if (method_exists($entity, 'getTitle')) {
            return $this->e((string) $entity->getTitle());
        }
        if (method_exists($entity, 'getName')) {
            return $this->e((string) $entity->getName());
        }

        return $this->e('#'.$id);
    }

    private function image(int $id): string
    {
        $asset = $this->assets->find($id);
        if (!$asset instanceof Asset) {
            return '';
        }

        $src = $this->thumbnails->thumb($id, 1200, 1200, 'contain');

        return $src === ''
            ? ''
            : sprintf('<img src="%s" alt="%s" loading="lazy">', $this->e($src), $this->e($asset->getOriginalName()));
    }

    private function file(int $id): string
    {
        $asset = $this->assets->find($id);
        if (!$asset instanceof Asset) {
            return '';
        }

        // A themable download route is a later concern; render the name safely for now.
        return sprintf('<span class="cp-field-file" data-asset-id="%d">%s</span>', $id, $this->e($asset->getOriginalName()));
    }

    private function selectLabel(FieldDefinition $definition, string $value, string $locale): string
    {
        $type = $this->types->get('select');
        if ($type instanceof SelectFieldType) {
            $choices = $type->choicesFor(new FieldContext($definition, $locale));

            return $choices[$value] ?? $value;
        }

        return $value;
    }

    private function formatDate(string $value, bool $withTime): string
    {
        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }

        return $dt->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
