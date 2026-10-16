<?php

declare(strict_types=1);

namespace Modules\Menu\Widget;

use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Core\Widget\WidgetTypeInterface;
use Modules\Menu\Repository\MenuRepository;
use Modules\Menu\Twig\FrontMenuRuntime;

/** Any of the site's navigation menus as a list of links, in the visitor's language. */
final class MenuWidget implements WidgetTypeInterface
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly FrontMenuRuntime $runtime,
    ) {
    }

    public function id(): string
    {
        return 'menu.navigation';
    }

    public function label(): string
    {
        return 'menu.widget.label';
    }

    public function description(): string
    {
        return 'menu.widget.description';
    }

    public function icon(): string
    {
        return 'heroicons:bars-3';
    }

    public function group(): string
    {
        return 'widget.group.general';
    }

    public function fields(): array
    {
        $options = [];
        foreach ($this->menus->findAll() as $menu) {
            $options[$menu->getIdentifier()] = $menu->getName();
        }

        return [
            WidgetField::select('menu', 'menu.widget.field.menu', $options, (string) (array_key_first($options) ?? '')),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $identifier = (string) ($settings['menu'] ?? '');
        if ($identifier === '') {
            return '';
        }

        return $this->branch($this->runtime->render($identifier, $context->locale));
    }

    /**
     * @param list<array{label: string, url: string, openInNewTab: bool, children: array}> $items
     */
    private function branch(array $items): string
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = WidgetHtml::link($item['url'], $item['label'], $item['openInNewTab'], (string) ($item['rel'] ?? ''))
                .(!empty($item['children']) ? $this->branch($item['children']) : '');
        }

        return WidgetHtml::ul($rows);
    }
}
