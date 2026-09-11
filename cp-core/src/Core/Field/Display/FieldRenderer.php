<?php

declare(strict_types=1);

namespace App\Core\Field\Display;

use App\Core\Display\Entity\EntityDisplay;
use App\Core\Display\EntityDisplayRegistry;
use App\Core\Display\ResolvedDisplayField;
use App\Core\Display\ViewModeRegistry;
use App\Core\Entity\FieldableInterface;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Reads and renders field values off a fieldable entity. Honours per-field
 * view_capability and never triggers extra content queries (values live in the
 * entity's field-value bag; references batch-load).
 */
final class FieldRenderer
{
    public function __construct(
        private readonly FieldDefinitionRegistry $definitions,
        private readonly FieldTypeRegistry $types,
        private readonly FieldFormatterResolver $formatter,
        private readonly ReferenceBatchLoader $references,
        private readonly Security $security,
        private readonly EntityDisplayRegistry $displays,
    ) {
    }

    /**
     * Raw stored value, or null when the field is undefined or view-denied.
     */
    public function value(FieldableInterface $entity, string $name): mixed
    {
        $definition = $this->definitions->getField($entity->fieldableBundle(), $name);
        if ($definition === null || !$this->canView($definition)) {
            return null;
        }

        return $entity->getFieldableData()[$name] ?? null;
    }

    public function has(FieldableInterface $entity, string $name): bool
    {
        $definition = $this->definitions->getField($entity->fieldableBundle(), $name);
        if ($definition !== null && $definition->getType() === 'rich_text') {
            [$html] = \App\Core\Field\Type\RichTextFieldType::extract($this->value($entity, $name));

            return trim($html) !== '';
        }

        $value = $this->value($entity, $name);

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Display HTML for one field (multi-value joined). Empty string when absent/denied.
     */
    public function render(FieldableInterface $entity, string $name): string
    {
        $definition = $this->definitions->getField($entity->fieldableBundle(), $name);
        if ($definition === null || !$this->canView($definition) || !$this->types->has($definition->getType())) {
            return '';
        }

        $raw = $entity->getFieldableData()[$name] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return '';
        }

        $locale = $entity->fieldableLocale();

        if ($definition->isMultiValue() && \is_array($raw) && !isset($raw['value'])) {
            $parts = array_filter(array_map(
                fn (mixed $item): string => $this->formatter->format($definition, $item, $locale, $entity),
                $raw,
            ), static fn (string $s): bool => $s !== '');

            return $parts === [] ? '' : '<ul class="cp-field-list"><li>'.implode('</li><li>', $parts).'</li></ul>';
        }

        return $this->formatter->format($definition, $raw, $locale, $entity);
    }

    /**
     * Render every visible field of the bundle (optionally one group), as
     * <div class="cp-field cp-field--{name}"> blocks.
     */
    public function renderGroup(FieldableInterface $entity, ?string $group = null): string
    {
        $out = '';
        foreach ($this->definitions->getFieldsForBundle($entity->fieldableBundle()) as $definition) {
            if ($group !== null && $definition->getFieldGroup() !== $group) {
                continue;
            }
            if (!$this->canView($definition)) {
                continue;
            }

            $body = $this->render($entity, $definition->getName());
            if ($body === '') {
                continue;
            }

            $out .= sprintf(
                '<div class="cp-field cp-field--%s"><span class="cp-field__label">%s</span><div class="cp-field__value">%s</div></div>',
                htmlspecialchars($definition->getName(), \ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($definition->getLabel(), \ENT_QUOTES, 'UTF-8'),
                $body,
            );
        }

        return $out;
    }

    /**
     * Render every field visible in one view mode (T2.2), ordered and labelled
     * per EntityDisplayRegistry — "default" with no configured overrides
     * renders identically to renderGroup(entity, null).
     */
    public function renderEntity(FieldableInterface $entity, string $viewMode = ViewModeRegistry::DEFAULT): string
    {
        $out = '';
        foreach ($this->displays->visibleFields($entity->fieldableBundle(), $viewMode) as $resolved) {
            if (!$this->canView($resolved->definition)) {
                continue;
            }

            $body = $this->render($entity, $resolved->definition->getName());
            if ($body === '') {
                continue;
            }

            $out .= $this->wrapField($resolved, $body);
        }

        return $out;
    }

    private function wrapField(ResolvedDisplayField $resolved, string $body): string
    {
        $definition = $resolved->definition;
        $name = htmlspecialchars($definition->getName(), \ENT_QUOTES, 'UTF-8');

        if ($resolved->labelDisplay === EntityDisplay::LABEL_HIDDEN) {
            return sprintf('<div class="cp-field cp-field--%s"><div class="cp-field__value">%s</div></div>', $name, $body);
        }

        $label = htmlspecialchars($definition->getLabel(), \ENT_QUOTES, 'UTF-8');

        if ($resolved->labelDisplay === EntityDisplay::LABEL_INLINE) {
            return sprintf(
                '<div class="cp-field cp-field--%s"><span class="cp-field__label">%s: </span>%s</div>',
                $name,
                $label,
                $body,
            );
        }

        return sprintf(
            '<div class="cp-field cp-field--%s"><span class="cp-field__label">%s</span><div class="cp-field__value">%s</div></div>',
            $name,
            $label,
            $body,
        );
    }

    /**
     * Batch-load references for a list of entities so renderGroup()/render() in a
     * loop stays at one query per target type.
     *
     * @param iterable<FieldableInterface> $entities
     */
    public function preloadReferences(iterable $entities): void
    {
        $seen = [];
        foreach ($entities as $entity) {
            $bundle = $entity->fieldableBundle();
            $defs = $seen[$bundle] ??= array_filter(
                $this->definitions->getFieldsForBundle($bundle),
                static fn (FieldDefinition $d): bool => $d->getType() === 'reference',
            );

            $data = $entity->getFieldableData();
            foreach ($defs as $definition) {
                $target = (string) $definition->getSetting('target', 'node');
                $raw = $data[$definition->getName()] ?? null;
                foreach ((array) $raw as $id) {
                    if (is_numeric($id)) {
                        $this->references->collect($target, (int) $id);
                    }
                }
            }
        }
    }

    private function canView(FieldDefinition $definition): bool
    {
        $capability = $definition->getViewCapability();

        return $capability === null || $this->security->isGranted($capability);
    }
}
