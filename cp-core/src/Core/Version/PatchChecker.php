<?php

declare(strict_types=1);

namespace App\Core\Version;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds out which file-level patches exist, and which one this installation
 * could apply next.
 *
 * Split the same way ReleaseChecker is, and for the same reason: refresh()
 * talks to the network, pending() never does. Patch state is read on an admin
 * screen; putting a GitHub fetch on that path would mean one slow DNS lookup
 * blocks the page an operator opened precisely because something was wrong.
 *
 * "Which one next" is deliberately singular. Patches form a chain — each names
 * the version it upgrades from — and this returns only the one whose base is
 * the running version. Offering 1.1.3 to a site on 1.1.0 would skip the file
 * changes in 1.1.1 and 1.1.2, and since a patch ships whole files rather than
 * diffs, the result would be a tree that is partly three versions ahead and
 * partly three behind, with nothing recording which parts are which.
 */
final class PatchChecker
{
    private const KEY = 'update.core.patch_index';

    private const SOURCE = 'https://raw.githubusercontent.com/CPalius/version/main/patches/index.json';

    /**
     * Hosts a manifest — and therefore the code it points at — may come from.
     * The same list ReleaseChecker applies to release pointers; widening it is
     * a decision to let a new party publish code that CPalius installs.
     *
     * @var list<string>
     */
    public const TRUSTED_PREFIXES = [
        'https://github.com/CPalius/',
        'https://raw.githubusercontent.com/CPalius/',
        'https://www.cpalius.com/',
        'https://cpalius.com/',
    ];

    private const TIMEOUT_SECONDS = 15;

    /** The index is a short list of pointers; the manifests it names are the payload. */
    private const MAX_INDEX_BYTES = 262144;
    private const MAX_MANIFEST_BYTES = 524288;

    /** @var list<array<string, mixed>>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Stored index entries, newest first. Never touches the network.
     *
     * @return list<array{version: string, base: string, released_at: ?string, critical: bool, summary: string, manifest: string}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            /** @var list<array{version: string, base: string, released_at: ?string, critical: bool, summary: string, manifest: string}> $memo */
            $memo = $this->memo;

            return $memo;
        }

        $raw = $this->settings->findOneBy(['settingKey' => self::KEY])?->getSettingValue();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        $entries = [];

        foreach (is_array($decoded['patches'] ?? null) ? $decoded['patches'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $version = $entry['version'] ?? null;
            $base = $entry['base'] ?? null;
            $manifest = $entry['manifest'] ?? null;

            if (!is_string($version) || !is_string($base) || !is_string($manifest)) {
                continue;
            }

            $entries[] = [
                'version' => $version,
                'base' => $base,
                'released_at' => is_string($entry['released_at'] ?? null) ? $entry['released_at'] : null,
                'critical' => (bool) ($entry['critical'] ?? false),
                'summary' => is_string($entry['summary'] ?? null) ? $entry['summary'] : '',
                'manifest' => $manifest,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        /** @var list<array{version: string, base: string, released_at: ?string, critical: bool, summary: string, manifest: string}> $entries */
        return $this->memo = $entries;
    }

    public function checkedAt(): ?string
    {
        $raw = $this->settings->findOneBy(['settingKey' => self::KEY])?->getSettingValue();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_string($decoded['checked_at'] ?? null) ? $decoded['checked_at'] : null;
    }

    /**
     * The index entry this installation could apply right now, or null.
     *
     * @return array{version: string, base: string, released_at: ?string, critical: bool, summary: string, manifest: string}|null
     */
    public function next(): ?array
    {
        $running = CpVersion::VERSION;

        foreach ($this->all() as $entry) {
            if (version_compare($entry['base'], $running, '==') && version_compare($entry['version'], $running, '>')) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Patches that exist but cannot be applied yet because an earlier one in
     * the chain has not been. Shown so the screen can say "1.1.3 is waiting
     * behind 1.1.1" instead of pretending it does not exist.
     *
     * @return list<array{version: string, base: string, released_at: ?string, critical: bool, summary: string, manifest: string}>
     */
    public function queued(): array
    {
        $running = CpVersion::VERSION;
        $next = $this->next();

        return array_values(array_filter(
            $this->all(),
            static fn (array $e): bool => version_compare($e['version'], $running, '>')
                && ($next === null || $e['version'] !== $next['version']),
        ));
    }

    /**
     * Refreshes only if the stored index is older than $maxAgeSeconds.
     *
     * The updates screen calls this so opening it shows current information
     * without waiting for tomorrow's cron. The staleness window is what stops a
     * browser refresh from turning into a request-per-render against GitHub.
     */
    public function refreshIfStale(int $maxAgeSeconds = 900): void
    {
        $checkedAt = $this->checkedAt();

        if ($checkedAt !== null) {
            $age = time() - (int) strtotime($checkedAt);

            if ($age >= 0 && $age < $maxAgeSeconds) {
                return;
            }
        }

        $this->refresh();
    }

    /**
     * Downloads and validates the manifest an index entry points at.
     *
     * @throws \RuntimeException when it cannot be fetched or does not validate
     */
    public function fetchManifest(string $url): PatchManifest
    {
        $trusted = false;

        foreach (self::TRUSTED_PREFIXES as $prefix) {
            if (str_starts_with($url, $prefix)) {
                $trusted = true;

                break;
            }
        }

        if (!$trusted) {
            throw new \RuntimeException(sprintf('Patch manifest URL "%s" is not a trusted CPalius location.', $url));
        }

        $body = $this->get($url, self::MAX_MANIFEST_BYTES);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Patch manifest is not valid JSON.');
        }

        return PatchManifest::parse($decoded, self::TRUSTED_PREFIXES);
    }

    /**
     * Fetches the index and stores it. Never throws — a failed check is not a
     * failed cron run, and the previous index is deliberately left in place so
     * a network blip cannot erase a pending security patch from the screen.
     */
    public function refresh(): string
    {
        try {
            $body = $this->get(self::SOURCE, self::MAX_INDEX_BYTES);
            $decoded = json_decode($body, true);

            if (!is_array($decoded) || (int) ($decoded['schema'] ?? 0) !== 1) {
                throw new \RuntimeException('Unusable patch index.');
            }

            $patches = is_array($decoded['patches'] ?? null) ? $decoded['patches'] : [];
        } catch (\Throwable $e) {
            return 'Patch index check failed, keeping last known state: '.$e->getMessage();
        }

        $this->persist($patches);

        $next = $this->next();

        return $next === null
            ? sprintf('No patch pending for %s.', CpVersion::VERSION)
            : sprintf('Patch available: %s (running %s).', $next['version'], CpVersion::VERSION);
    }

    private function get(string $url, int $maxBytes): string
    {
        $response = $this->client()->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('HTTP '.$response->getStatusCode());
        }

        $body = $response->getContent(false);

        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException('Payload too large');
        }

        return $body;
    }

    private function client(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create([
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'headers' => ['User-Agent' => 'CPalius/'.CpVersion::VERSION.' (+https://www.cpalius.com)'],
        ]);
    }

    /**
     * @param array<int, mixed> $patches
     */
    private function persist(array $patches): void
    {
        $payload = [
            'patches' => array_values(array_filter($patches, is_array(...))),
            'checked_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $setting = $this->settings->findOneBy(['settingKey' => self::KEY]);

        if (!$setting instanceof Setting) {
            $setting = new Setting(self::KEY, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->entityManager->flush();

        $this->memo = null;
    }
}
