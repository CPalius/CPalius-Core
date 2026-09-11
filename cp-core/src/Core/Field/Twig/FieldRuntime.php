<?php

declare(strict_types=1);

namespace App\Core\Field\Twig;

use App\Core\Display\ViewModeRegistry;
use App\Core\Entity\FieldableInterface;
use App\Core\Field\Display\FieldRenderer;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;

final class FieldRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly FieldRenderer $renderer,
    ) {
    }

    public function field(?FieldableInterface $entity, string $name): Markup
    {
        return new Markup($entity === null ? '' : $this->renderer->render($entity, $name), 'UTF-8');
    }

    public function value(?FieldableInterface $entity, string $name): mixed
    {
        return $entity === null ? null : $this->renderer->value($entity, $name);
    }

    public function fields(?FieldableInterface $entity, ?string $group = null): Markup
    {
        return new Markup($entity === null ? '' : $this->renderer->renderGroup($entity, $group), 'UTF-8');
    }

    /**
     * T2.2 — every field visible in one view mode, ordered/labelled per
     * EntityDisplayRegistry. "default" with no configured overrides renders
     * the same as fields($entity) above.
     */
    public function view(?FieldableInterface $entity, string $viewMode = ViewModeRegistry::DEFAULT): Markup
    {
        return new Markup($entity === null ? '' : $this->renderer->renderEntity($entity, $viewMode), 'UTF-8');
    }

    public function has(?FieldableInterface $entity, string $name): bool
    {
        return $entity !== null && $this->renderer->has($entity, $name);
    }

    /**
     * @param iterable<FieldableInterface> $entities
     */
    public function preload(iterable $entities): void
    {
        $this->renderer->preloadReferences($entities);
    }
}
