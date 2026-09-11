<?php

declare(strict_types=1);

namespace Modules\Pages\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Asset;
use App\Entity\Node;
use App\Repository\AssetRepository;
use Modules\Pages\Field\PageFieldType;
use Modules\Pages\PageTemplate;

/**
 * Front presentation: featured image, ACF values, custom CSS/JS.
 */
final class PagePresentationService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    /**
     * @return array{
     *     page: Node,
     *     featuredImageUrl: ?string,
     *     customFields: list<array<string, mixed>>,
     *     customCss: string,
     *     customJs: string,
     *     template: string
     * }
     */
    public function viewData(Node $page): array
    {
        return [
            'page' => $page,
            'featuredImageUrl' => $this->resolveFeaturedImageUrl($page),
            'customFields' => $this->presentFields($page),
            'customCss' => (string) $page->getDataValue('custom_css', ''),
            'customJs' => $this->isCustomJsAllowed() ? (string) $page->getDataValue('custom_js', '') : '',
            'template' => $this->resolveTemplate($page),
        ];
    }

    public function resolveFeaturedImageUrl(Node $page): ?string
    {
        return $this->assetUrl($page->getDataValue('featured_image_asset_id'));
    }

    /**
     * @return list<array{key: string, type: string, label: string, value: mixed, url: ?string, urls: list<string>}>
     */
    public function presentFields(Node $page): array
    {
        $raw = $page->getDataValue('custom_fields', []);
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? PageFieldType::TEXT);
            $value = $row['value'] ?? null;
            $url = null;
            $urls = [];

            if ($type === PageFieldType::IMAGE) {
                $url = $this->assetUrl($value);
            } elseif ($type === PageFieldType::GALLERY && \is_array($value)) {
                foreach ($value as $id) {
                    $resolved = $this->assetUrl($id);
                    if ($resolved !== null) {
                        $urls[] = $resolved;
                    }
                }
            }

            $out[] = [
                'key' => (string) ($row['key'] ?? ''),
                'type' => $type,
                'label' => (string) ($row['label'] ?? ''),
                'value' => $value,
                'url' => $url,
                'urls' => $urls,
            ];
        }

        return $out;
    }

    public function resolveTemplate(Node $page): string
    {
        $template = (string) $page->getDataValue('template', PageTemplate::DEFAULT);

        return PageTemplate::isValid($template) ? $template : PageTemplate::DEFAULT;
    }

    private function assetUrl(mixed $assetId): ?string
    {
        if (!is_numeric($assetId)) {
            return null;
        }

        $asset = $this->assetRepository->find((int) $assetId);

        return $asset instanceof Asset && $asset->getStorageKey() !== null
            ? '/uploads/'.$asset->getStorageKey()
            : null;
    }

    private function isCustomJsAllowed(): bool
    {
        $value = $this->settingsRegistry->get('pages.allow_custom_js', true);

        return $value === true || $value === 1 || $value === '1';
    }
}
