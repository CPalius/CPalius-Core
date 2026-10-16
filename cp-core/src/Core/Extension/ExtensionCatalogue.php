<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Module\ModuleRegistry;
use App\Core\Settings\SettingsRegistry;
use App\Core\Theme\ThemeRegistry;
use App\Core\Version\CpVersion;
use App\Core\Version\ReleaseChecker;
use App\Core\Version\Service\ReleaseSecurityException;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Composer\Semver\Semver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The signed list of installable modules and themes, and how it compares with this installation.
 *
 * Like ReleaseChecker, refresh() talks to the network (from cron or the store screen) and every
 * other method reads the stored copy, so rendering a page never waits on the update server.
 * Preview images named in the index are downloaded once into var/store-cache and served by the
 * panel itself, so the browser never contacts the update server.
 */
final class ExtensionCatalogue
{
    public const INDEX_URL = ReleaseChecker::FEED.'extensions/index.json';
    public const SIGNATURE_URL = ReleaseChecker::FEED.'extensions/index.sig';

    private const KEY = 'update.extensions.index';
    private const ERROR_KEY = 'update.extensions.error';
    private const TIMEOUT_SECONDS = 8;
    private const MAX_BYTES = 1048576;
    private const MAX_IMAGE_BYTES = 2097152;
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** @var array{checked_at: string, index: array<string, mixed>}|false|null */
    private array|false|null $memo = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly ExtensionIndexVerifier $verifier,
        private readonly ModuleRegistry $modules,
        private readonly ThemeRegistry $themes,
    ) {
    }

    /**
     * Fetches, verifies and stores the index. Never throws; returns a line for the log.
     */
    public function refresh(): string
    {
        try {
            $client = $this->client();
            $body = $this->fetch($client, self::INDEX_URL);
            $signature = $this->fetch($client, self::SIGNATURE_URL);
            $index = $this->verifier->verify($body, $signature, (int) ($this->stored()['index']['serial'] ?? 0) ?: null);
        } catch (ReleaseSecurityException $e) {
            $this->store(self::ERROR_KEY, $e->getMessage());

            return 'Extension index refused: '.$e->getMessage();
        } catch (\Throwable $e) {
            return 'Extension index check failed, keeping the last copy: '.$e->getMessage();
        }

        $this->store(self::KEY, json_encode(['checked_at' => date(\DATE_ATOM), 'index' => $index], \JSON_THROW_ON_ERROR));
        $this->store(self::ERROR_KEY, '');
        $this->cacheScreenshots($index);

        return 'Extension index updated (serial '.(int) ($index['serial'] ?? 0).').';
    }

    public function refreshIfStale(int $maxAgeSeconds = 3600): void
    {
        $checked = $this->stored()['checked_at'] ?? null;

        if (\is_string($checked) && time() - (int) strtotime($checked) < $maxAgeSeconds) {
            return;
        }

        $this->refresh();
    }

    /**
     * The verified index as last stored, or null when none has been fetched yet.
     *
     * @return array<string, mixed>|null
     */
    public function rawIndex(): ?array
    {
        $index = $this->stored()['index'] ?? null;

        return \is_array($index) ? $index : null;
    }

    public function checkedAt(): ?string
    {
        $checked = $this->stored()['checked_at'] ?? null;

        return \is_string($checked) ? $checked : null;
    }

    /**
     * Why the last fetched index was refused, or null.
     */
    public function securityError(): ?string
    {
        $raw = $this->settingsRegistry->getRaw(self::ERROR_KEY);

        return \is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * Every extension of one type, merged with what is installed. Installed ones the catalogue
     * does not list (your own modules) are included too, so the store shows the whole picture.
     *
     * @param string $type "modules" or "themes"
     *
     * @return list<array<string, mixed>>
     */
    public function entries(string $type): array
    {
        $installed = $this->installedInfo($type);
        $out = [];

        foreach ((array) ($this->stored()['index'][$type] ?? []) as $name => $entry) {
            if (!\is_array($entry) || !\is_string($name)) {
                continue;
            }

            $latest = (string) ($entry['latest'] ?? '');
            $meta = (array) ($entry['versions'][$latest] ?? []);
            $have = $installed[$name] ?? null;
            [$compatible, $problems] = $this->compatibility($meta);

            $status = 'not_installed';
            if ($have !== null) {
                $status = version_compare($latest, $have['version'], '>') ? 'update' : 'installed';
            }

            $out[] = [
                'name' => $name,
                'type' => $type,
                'title' => (string) ($entry['title'] ?? $name),
                'summary' => (string) ($entry['summary'] ?? ''),
                'category' => (string) ($entry['category'] ?? ''),
                'extends' => (string) ($entry['extends'] ?? ''),
                'free' => (bool) ($entry['free'] ?? true),
                'bundled' => (bool) ($entry['bundled'] ?? false),
                'latest' => $latest,
                'installed' => $have['version'] ?? null,
                'state' => $have['status'] ?? 'not_installed',
                'status' => $status,
                'compatible' => $compatible,
                'problems' => $problems,
                'size' => (int) ($meta['size'] ?? 0),
                'released_at' => (string) ($meta['released_at'] ?? ''),
                'notes' => (string) ($meta['notes'] ?? ''),
                'local_only' => false,
                'screenshot' => $this->screenshotPath($type, $name) !== null,
                'advisories' => $have !== null ? $this->matchingAdvisories($entry, $have['version']) : [],
            ];
        }

        $listed = array_column($out, 'name');

        foreach ($installed as $name => $info) {
            if (\in_array($name, $listed, true)) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'type' => $type,
                'title' => $info['title'],
                'summary' => $info['description'],
                'category' => '',
                'extends' => '',
                'free' => true,
                'bundled' => false,
                'latest' => $info['version'],
                'installed' => $info['version'],
                'state' => $info['status'],
                'status' => 'installed',
                'compatible' => true,
                'problems' => [],
                'size' => 0,
                'released_at' => '',
                'notes' => '',
                'local_only' => true,
                'screenshot' => $this->screenshotPath($type, $name) !== null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $out;
    }

    /**
     * @return array<string, mixed>|null the index entry with all its versions
     */
    public function find(string $type, string $name): ?array
    {
        $entry = $this->stored()['index'][$type][$name] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    /**
     * Security advisories the signed index publishes for versions that are installed here.
     *
     * An entry carries "advisories": [{"id", "affected", "fixed", "severity", "summary", "url"}]. The index is
     * signature-checked before it is stored, so a mirror or a man in the middle cannot invent or hide one.
     *
     * @return list<array{type: string, name: string, title: string, installed: string, id: string, severity: string, summary: string, fixed: string, url: string}>
     */
    public function advisories(): array
    {
        $out = [];

        foreach (['modules', 'themes'] as $type) {
            $installed = $this->installedInfo($type);

            foreach ((array) ($this->stored()['index'][$type] ?? []) as $name => $entry) {
                $have = \is_string($name) ? ($installed[$name] ?? null) : null;
                if ($have === null || !\is_array($entry)) {
                    continue;
                }

                foreach ($this->matchingAdvisories($entry, $have['version']) as $advisory) {
                    $out[] = ['type' => $type, 'name' => $name, 'title' => $have['title'], 'installed' => $have['version']] + $advisory;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array{id: string, severity: string, summary: string, fixed: string, url: string}>
     */
    private function matchingAdvisories(array $entry, string $version): array
    {
        $out = [];

        foreach ((array) ($entry['advisories'] ?? []) as $advisory) {
            if (!\is_array($advisory) || !\is_string($advisory['affected'] ?? null) || !AdvisoryMatcher::affects($version, $advisory['affected'])) {
                continue;
            }

            $severity = (string) ($advisory['severity'] ?? 'high');
            $out[] = [
                'id' => (string) ($advisory['id'] ?? ''),
                'severity' => \in_array($severity, ['critical', 'high', 'medium', 'low'], true) ? $severity : 'high',
                'summary' => mb_substr((string) ($advisory['summary'] ?? ''), 0, 300),
                'fixed' => (string) ($advisory['fixed'] ?? ''),
                'url' => preg_match('~^https://~i', (string) ($advisory['url'] ?? '')) === 1 ? (string) $advisory['url'] : '',
            ];
        }

        return $out;
    }

    public function updateCount(): int
    {
        $count = 0;

        foreach (['modules', 'themes'] as $type) {
            $count += \count(array_filter($this->entries($type), static fn (array $e): bool => $e['status'] === 'update'));
        }

        return $count;
    }

    /**
     * The preview image to show: the one inside the installed extension first, then the cached
     * copy of the one the catalogue publishes.
     */
    public function screenshotPath(string $type, string $name): ?string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1 || !\in_array($type, ['modules', 'themes'], true)) {
            return null;
        }

        $dir = $this->projectDir.'/cp-content/'.$type.'/'.$name;

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            if (is_file($dir.'/screenshot.'.$extension)) {
                return $dir.'/screenshot.'.$extension;
            }
        }

        $theme = $type === 'themes' ? $this->themes->get($name) : null;
        $themeShot = $theme !== null ? $this->themes->screenshotPath($theme) : null;

        if ($themeShot !== null) {
            return $themeShot;
        }

        return (glob($this->cacheDir().'/'.$type.'-'.$name.'-*') ?: [])[0] ?? null;
    }

    /**
     * @param array<string, mixed> $versionMeta
     *
     * @return array{0: bool, 1: list<string>} compatible, reasons when not
     */
    public function compatibility(array $versionMeta): array
    {
        $problems = [];
        $constraint = (string) ($versionMeta['core_version'] ?? '');

        try {
            if ($constraint !== '' && !Semver::satisfies(CpVersion::VERSION, $constraint)) {
                $problems[] = 'core:'.$constraint;
            }
        } catch (\Throwable) {
            $problems[] = 'core:'.$constraint;
        }

        $modules = $this->installed('modules');

        foreach ((array) ($versionMeta['requires'] ?? []) as $required => $range) {
            $have = $modules[(string) $required] ?? null;

            try {
                if ($have === null || (\is_string($range) && $range !== '' && !Semver::satisfies($have, $range))) {
                    $problems[] = 'module:'.$required;
                }
            } catch (\Throwable) {
                $problems[] = 'module:'.$required;
            }
        }

        return [$problems === [], $problems];
    }

    /**
     * @return array<string, string> directory name => installed version
     */
    public function installed(string $type): array
    {
        return array_map(static fn (array $info): string => $info['version'], $this->installedInfo($type));
    }

    /**
     * @return array<string, array{version: string, status: string, title: string, description: string}>
     */
    public function installedInfo(string $type): array
    {
        $out = [];

        if ($type === 'modules') {
            foreach ($this->modules->discoverAllModules() as $module) {
                $out[$module['dirName']] = [
                    'version' => (string) $module['version'],
                    'status' => (string) $module['status'],
                    'title' => (string) $module['name'],
                    'description' => (string) $module['description'],
                ];
            }

            return $out;
        }

        $active = $this->themes->activeDirName();

        foreach ($this->themes->all() as $dirName => $theme) {
            $out[(string) $dirName] = [
                'version' => $theme->version,
                'status' => (string) $dirName === $active ? 'active' : 'inactive',
                'title' => $theme->name,
                'description' => $theme->description,
            ];
        }

        return $out;
    }

    public function cacheDir(): string
    {
        return $this->projectDir.'/cp-core/var/store-cache';
    }

    /**
     * Downloads preview images that are not cached yet. Failures are skipped; a missing image is a placeholder.
     *
     * @param array<string, mixed> $index
     */
    private function cacheScreenshots(array $index): void
    {
        $client = $this->client();

        foreach (['modules', 'themes'] as $type) {
            foreach ((array) ($index[$type] ?? []) as $name => $entry) {
                $shot = \is_array($entry) ? ($entry['screenshot'] ?? null) : null;
                $path = \is_array($shot) ? (string) ($shot['path'] ?? '') : '';
                $sha = \is_array($shot) ? strtolower((string) ($shot['sha256'] ?? '')) : '';
                $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

                if (!\is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1
                    || !\in_array($extension, self::IMAGE_EXTENSIONS, true) || str_contains($path, '..') || !str_starts_with($path, $type.'/')) {
                    continue;
                }

                $target = $this->cacheDir().'/'.$type.'-'.$name.'-'.substr($sha, 0, 12).'.'.$extension;

                if (is_file($target)) {
                    continue;
                }

                try {
                    $response = $client->request('GET', ReleaseChecker::FEED.$path);
                    $data = $response->getStatusCode() === 200 ? $response->getContent(false) : '';
                } catch (\Throwable) {
                    continue;
                }

                if ($data === '' || \strlen($data) > self::MAX_IMAGE_BYTES || !hash_equals($sha, hash('sha256', $data))) {
                    continue;
                }

                if (!is_dir($this->cacheDir()) && !@mkdir($this->cacheDir(), 0775, true) && !is_dir($this->cacheDir())) {
                    return;
                }

                foreach (glob($this->cacheDir().'/'.$type.'-'.$name.'-*') ?: [] as $old) {
                    @unlink($old);
                }

                file_put_contents($target, $data);
            }
        }
    }

    private function client(): \Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return HttpClient::create(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS * 2, 'headers' => ['User-Agent' => 'CPalius/'.CpVersion::VERSION]]);
    }

    /**
     * @return array{checked_at?: string, index?: array<string, mixed>}
     */
    private function stored(): array
    {
        if ($this->memo !== null) {
            return $this->memo === false ? [] : $this->memo;
        }

        $raw = $this->settingsRegistry->getRaw(self::KEY);
        $data = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!\is_array($data) || !\is_array($data['index'] ?? null)) {
            $this->memo = false;

            return [];
        }

        return $this->memo = $data;
    }

    private function fetch(\Symfony\Contracts\HttpClient\HttpClientInterface $client, string $url): string
    {
        $response = $client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('HTTP '.$response->getStatusCode().' for '.$url);
        }

        $body = $response->getContent(false);

        if (\strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('Payload too large: '.$url);
        }

        return $body;
    }

    private function store(string $key, string $value): void
    {
        $setting = $this->settings->findOneBy(['settingKey' => $key]);

        if (!$setting instanceof Setting) {
            $setting = new Setting($key);
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($value);
        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();
        $this->memo = null;
    }
}
