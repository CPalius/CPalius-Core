<?php

declare(strict_types=1);

namespace App\Core\Widget\Twig;

use App\Core\Widget\WidgetAreaRendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Fail-safe bridge: no renderer (Widget module off) or a renderer that throws both give '' instead of an
 * error page, so a theme can place an area without caring whether the module is there.
 */
final class WidgetRuntime implements RuntimeExtensionInterface
{
    /**
     * @param iterable<WidgetAreaRendererInterface> $renderers
     */
    public function __construct(
        #[TaggedIterator('cpalius.widget_area_renderer')]
        private readonly iterable $renderers,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $area, array $context = []): string
    {
        foreach ($this->renderers as $renderer) {
            try {
                return $renderer->render($area, $context);
            } catch (\Throwable $e) {
                $this->logger->warning('Widget area failed to render.', ['area' => $area, 'error' => $e->getMessage()]);

                return '';
            }
        }

        return '';
    }
}
