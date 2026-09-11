<?php

declare(strict_types=1);

namespace App\Core\Portal;

use App\Core\Localization\LocaleProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Studio editor for portal showcase copy. Reads/writes nested portal.{locale}.yaml per active locale.
 */
final class PortalCopyService
{
    private const DOMAIN = 'portal';

    /**
     * Block id => YAML root key under portal.{locale}.yaml.
     *
     * @var array<string, string>
     */
    private const ROOT_BY_BLOCK = [
        'hero' => 'hero',
        'about' => 'about',
        'architecture' => 'architecture',
        'core' => 'core',
        'features' => 'features',
        'techstack' => 'tech',
        'entity' => 'entity',
        'audit' => 'audit',
        'platform' => 'platform',
        'aacp' => 'aacp',
        'modules' => 'modules',
        'forum_engine' => 'forum',
        'media_pipeline' => 'media',
        'localization' => 'localization',
        'security' => 'security',
        'manifesto' => 'manifesto',
        'stats' => 'stats',
        'roadmap' => 'roadmap',
        'community' => 'community',
    ];

    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly Filesystem $filesystem,
        private readonly KernelInterface $kernel,
        private readonly string $translationsDir,
    ) {
    }

    public function supports(string $blockId): bool
    {
        return isset(self::ROOT_BY_BLOCK[$blockId]);
    }

    /**
     * Active locale codes for Studio tabs.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->localeProvider->getCodes();
    }

    /**
     * Relative leaf keys under the block root (e.g. "label", "line1_p1_html").
     *
     * @return list<string>
     */
    public function fieldKeys(string $blockId): array
    {
        $root = self::ROOT_BY_BLOCK[$blockId] ?? null;
        if ($root === null) {
            return [];
        }

        $keys = [];
        foreach ($this->locales() as $locale) {
            $section = $this->loadSection($locale, $root);
            foreach ($this->flatten($section) as $relKey => $_) {
                $keys[$relKey] = true;
            }
        }

        $list = array_keys($keys);
        sort($list);

        return $list;
    }

    /**
     * Values keyed by locale then relative field key.
     *
     * @return array<string, array<string, string>>
     */
    public function valuesForBlock(string $blockId): array
    {
        $root = self::ROOT_BY_BLOCK[$blockId] ?? null;
        if ($root === null) {
            return [];
        }

        $out = [];
        foreach ($this->locales() as $locale) {
            $out[$locale] = $this->flatten($this->loadSection($locale, $root));
        }

        return $out;
    }

    /**
     * All copy-capable blocks: values for the Studio form.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function valuesForAllBlocks(): array
    {
        $out = [];
        foreach (array_keys(self::ROOT_BY_BLOCK) as $blockId) {
            $out[$blockId] = $this->valuesForBlock($blockId);
        }

        return $out;
    }

    /**
     * Persist Studio payload: [blockId => [locale => [relativeKey => value]]].
     *
     * @param array<string, mixed> $payload
     */
    public function saveAll(array $payload): void
    {
        $locales = $this->locales();

        foreach ($payload as $blockId => $byLocale) {
            if (!\is_string($blockId) || !isset(self::ROOT_BY_BLOCK[$blockId]) || !\is_array($byLocale)) {
                continue;
            }

            $this->saveBlock($blockId, $byLocale, false);
        }

        $this->invalidateTranslatorCache();
    }

    /**
     * @param array<string, mixed> $byLocale locale => [relativeKey => value]
     */
    public function saveBlock(string $blockId, array $byLocale, bool $invalidateCache = true): void
    {
        if (!isset(self::ROOT_BY_BLOCK[$blockId])) {
            throw new \InvalidArgumentException(sprintf('Unknown portal block "%s".', $blockId));
        }

        $root = self::ROOT_BY_BLOCK[$blockId];
        $locales = $this->locales();

        foreach ($byLocale as $locale => $fields) {
            if (!\is_string($locale) || !\in_array($locale, $locales, true) || !\is_array($fields)) {
                continue;
            }

            $this->saveSectionFields($locale, $root, $fields);
        }

        if ($invalidateCache) {
            $this->invalidateTranslatorCache();
        }
    }

    /**
     * Restore one block section from factory defaults for every active locale.
     *
     * @return array<string, array<string, string>> refreshed values
     */
    public function resetBlock(string $blockId): array
    {
        if (!isset(self::ROOT_BY_BLOCK[$blockId])) {
            throw new \InvalidArgumentException(sprintf('Unknown portal block "%s".', $blockId));
        }

        $root = self::ROOT_BY_BLOCK[$blockId];

        foreach ($this->locales() as $locale) {
            $defaults = $this->loadFile($this->defaultsFilePath($locale));
            $section = $defaults[$root] ?? [];
            if (!\is_array($section)) {
                $section = [];
            }

            $live = $this->loadFile($this->filePath($locale));
            $live[$root] = $section;
            $this->writeAtomic($this->filePath($locale), $live);
        }

        $this->invalidateTranslatorCache();

        return $this->valuesForBlock($blockId);
    }

    /**
     * Restore entire portal.{locale}.yaml files from factory defaults.
     */
    public function resetAll(): void
    {
        foreach ($this->locales() as $locale) {
            $defaultPath = $this->defaultsFilePath($locale);
            $livePath = $this->filePath($locale);

            if (!is_file($defaultPath)) {
                throw new \RuntimeException(sprintf('Missing factory default file: %s', basename($defaultPath)));
            }

            $this->filesystem->copy($defaultPath, $livePath, true);
        }

        $this->invalidateTranslatorCache();
    }

    /**
     * Locale tabs for Studio (code + display label).
     *
     * @return list<array{code: string, label: string}>
     */
    public function localeTabs(): array
    {
        $tabs = [];
        foreach ($this->localeProvider->getLocales() as $definition) {
            $label = $definition->nativeName !== '' ? $definition->nativeName : strtoupper($definition->code);
            $tabs[] = [
                'code' => $definition->code,
                'label' => sprintf('%s (%s)', strtoupper($definition->code), $label),
            ];
        }

        return $tabs;
    }

    public function isTextareaField(string $relativeKey): bool
    {
        return str_ends_with($relativeKey, '_html')
            || str_ends_with($relativeKey, '_code')
            || str_contains($relativeKey, 'code')
            || str_ends_with($relativeKey, '_text')
            || str_ends_with($relativeKey, '_desc')
            || $relativeKey === 'desc'
            || str_contains($relativeKey, 'p1')
            || str_contains($relativeKey, 'p2');
    }

    private function defaultsFilePath(string $locale): string
    {
        return rtrim($this->translationsDir, '/\\')
            .DIRECTORY_SEPARATOR.'.defaults'
            .DIRECTORY_SEPARATOR.self::DOMAIN.'.'.$locale.'.yaml';
    }

    /**
     * @param array<string, mixed> $fields relativeKey => value
     */
    private function saveSectionFields(string $locale, string $root, array $fields): void
    {
        $path = $this->filePath($locale);
        $data = $this->loadFile($path);

        if (!isset($data[$root]) || !\is_array($data[$root])) {
            $data[$root] = [];
        }

        /** @var array<string, mixed> $section */
        $section = $data[$root];

        foreach ($fields as $relKey => $value) {
            if (!\is_string($relKey) || $relKey === '' || !\is_scalar($value)) {
                continue;
            }

            $this->setNested($section, $relKey, (string) $value);
        }

        $data[$root] = $section;
        $this->writeAtomic($path, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSection(string $locale, string $root): array
    {
        $data = $this->loadFile($this->filePath($locale));
        $section = $data[$root] ?? [];

        return \is_array($section) ? $section : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException) {
            return [];
        }

        return \is_array($parsed) ? $parsed : [];
    }

    private function filePath(string $locale): string
    {
        return rtrim($this->translationsDir, '/\\').DIRECTORY_SEPARATOR.self::DOMAIN.'.'.$locale.'.yaml';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix.'.'.$key;

            if (\is_array($value)) {
                $out += $this->flatten($value, $path);
                continue;
            }

            if (\is_scalar($value) || $value === null) {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setNested(array &$data, string $dotPath, string $value): void
    {
        $parts = explode('.', $dotPath);
        $ref = &$data;

        foreach ($parts as $i => $part) {
            $isLast = $i === \count($parts) - 1;
            if ($isLast) {
                $ref[$part] = $value;

                return;
            }

            if (!isset($ref[$part]) || !\is_array($ref[$part])) {
                $ref[$part] = [];
            }

            $ref = &$ref[$part];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeAtomic(string $path, array $data): void
    {
        $dir = \dirname($path);

        try {
            if (!is_dir($dir)) {
                $this->filesystem->mkdir($dir);
            }

            $yaml = Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
            $this->filesystem->dumpFile($tmp, $yaml);
            $this->filesystem->rename($tmp, $path, true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to write portal copy file "%s": %s', basename($path), $e->getMessage()), 0, $e);
        }
    }

    private function invalidateTranslatorCache(): void
    {
        $dir = $this->kernel->getCacheDir().'/translations';
        if (is_dir($dir)) {
            $this->filesystem->remove($dir);
        }
    }
}
