<?php

declare(strict_types=1);

namespace Modules\Menu\Location;

use App\Core\Theme\ThemeRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every place a menu can be assigned to: the built-in defaults, tagged module providers
 * (cpalius.menu_location) and the active theme's theme.json "menu_locations".
 */
final class MenuLocationRegistry
{
    private const ID_PATTERN = '/^[a-z0-9_]{1,60}$/';

    /** @var array<string, string>|null */
    private ?array $locations = null;

    /**
     * @param iterable<MenuLocationProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('cpalius.menu_location')]
        private readonly iterable $providers,
        private readonly ThemeRegistry $themes,
    ) {
    }

    /**
     * @return array<string, string> id => label or translation key
     */
    public function all(): array
    {
        if ($this->locations !== null) {
            return $this->locations;
        }

        $locations = [
            'main_header' => 'menu.location.main_header',
            'footer_primary' => 'menu.location.footer_primary',
        ];

        foreach ($this->providers as $provider) {
            $locations = $this->merge($locations, $provider->locations());
        }

        return $this->locations = $this->merge($locations, $this->themeLocations());
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * @return array<string, string>
     */
    private function themeLocations(): array
    {
        $file = $this->themes->themesDir().'/'.$this->themes->activeDirName().'/theme.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $declared = \is_array($data) ? ($data['menu_locations'] ?? []) : [];

        return \is_array($declared) ? $declared : [];
    }

    /**
     * @param array<string, string> $base
     * @param array<mixed>          $extra
     *
     * @return array<string, string>
     */
    private function merge(array $base, array $extra): array
    {
        foreach ($extra as $id => $label) {
            if (\is_string($id) && \is_string($label) && $label !== '' && preg_match(self::ID_PATTERN, $id) === 1) {
                $base[$id] = $label;
            }
        }

        return $base;
    }
}
