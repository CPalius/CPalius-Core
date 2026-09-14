<?php

declare(strict_types=1);

namespace App\Core\Version;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Asks the CPalius/version repository what the current release is, and
 * remembers the answer.
 *
 * The split that matters: refresh() talks to the network, status() never does.
 * A version check is a nice-to-have; a page render is not. Fetching over HTTP
 * while rendering would put GitHub's availability on the critical path of every
 * request that draws a footer — one DNS timeout and the whole site stalls for
 * however long the connect timeout is. So the network call happens on cron, the
 * result is written to cp_settings, and request-time code reads that row.
 *
 * Stored in cp_settings rather than a table of its own, following the same
 * convention as UpdateHookLedger: one JSON blob under one key needs no
 * migration, which keeps the checker working on an installation that has not
 * applied one yet.
 */
final class ReleaseChecker
{
    private const KEY = 'update.core.latest_release';

    /**
     * Raw pointer file. Deliberately raw.githubusercontent.com and not the API:
     * the API rate-limits unauthenticated callers to 60 requests/hour per IP,
     * which shared hosting would exhaust on behalf of every site on the box.
     * The raw host has no such limit and needs no credentials.
     */
    private const SOURCE = 'https://raw.githubusercontent.com/CPalius/version/main/latest.json';

    /** Give up quickly — cron should not hang on an unreachable host. */
    private const TIMEOUT_SECONDS = 8;

    /** Refuse absurd payloads; the real file is well under a kilobyte. */
    private const MAX_BYTES = 65536;

    /** Release notes are prose, not a payload — cap them well below a megabyte. */
    private const MAX_NOTES_BYTES = 262144;

    /**
     * Per-request memo. false means "checked, nothing stored" — distinct from
     * null, which means "not looked at yet"; without that distinction a fresh
     * installation would query on every call that returns nothing.
     *
     * @var array<string, mixed>|false|null
     */
    private array|false|null $memo = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function runningVersion(): string
    {
        return CpVersion::VERSION;
    }

    /**
     * The last known release state, without touching the network.
     *
     * Returns null when no check has succeeded yet — callers must treat that as
     * "unknown", never as "up to date". Rendering "you are current" off a check
     * that never ran is worse than rendering nothing.
     *
     * @return array{version: string, released_at: ?string, critical: bool, notes_url: ?string, notes_raw: ?string, download_zip: ?string, download_sha256: ?string, checked_at: ?string, outdated: bool}|null
     */
    public function status(): ?array
    {
        // Read through the repository and memoise, exactly as UpdateHookLedger
        // does. SettingsRegistry is not an option: it resolves only keys that
        // have a registered SettingDefinition and silently returns the default
        // for anything else, and this key is internal bookkeeping rather than
        // an operator-editable setting — putting it in the settings UI to make
        // the read work would be the tail wagging the dog.
        //
        // The cost is one indexed lookup, once per request, and only on pages
        // that actually ask — which is the admin layouts. The public footer
        // renders the version from a constant and never calls this.
        if ($this->memo !== null) {
            return $this->memo === false ? null : $this->memo;
        }

        $raw = $this->settings->findOneBy(['settingKey' => self::KEY])?->getSettingValue();

        if (!\is_string($raw) || trim($raw) === '') {
            $this->memo = false;

            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded) || !isset($decoded['version']) || !\is_string($decoded['version'])) {
            $this->memo = false;

            return null;
        }

        $latest = $decoded['version'];

        return $this->memo = [
            'version' => $latest,
            'released_at' => \is_string($decoded['released_at'] ?? null) ? $decoded['released_at'] : null,
            'critical' => (bool) ($decoded['critical'] ?? false),
            'notes_url' => \is_string($decoded['notes_url'] ?? null) ? $decoded['notes_url'] : null,
            'notes_raw' => \is_string($decoded['notes_raw'] ?? null) ? $decoded['notes_raw'] : null,
            'download_zip' => \is_string($decoded['download_zip'] ?? null) ? $decoded['download_zip'] : null,
            'download_sha256' => \is_string($decoded['download_sha256'] ?? null) ? $decoded['download_sha256'] : null,
            'checked_at' => \is_string($decoded['checked_at'] ?? null) ? $decoded['checked_at'] : null,
            // Recomputed on read, not stored: the running version changes when
            // files are overwritten by an update, and that must flip "outdated"
            // to false immediately — without waiting for the next cron run to
            // clear a stale flag.
            'outdated' => version_compare($latest, CpVersion::VERSION, '>'),
        ];
    }

    public function isOutdated(): bool
    {
        return $this->status()['outdated'] ?? false;
    }

    /**
     * Refreshes only if the stored result is older than $maxAgeSeconds.
     *
     * The updates screen calls this so that opening it shows current
     * information without waiting for tomorrow's cron. The staleness window is
     * what stops a browser refresh — or an admin leaving the tab open with a
     * reloader — from turning into a request-per-render against GitHub.
     */
    public function refreshIfStale(int $maxAgeSeconds = 900): void
    {
        $checkedAt = $this->status()['checked_at'] ?? null;

        if ($checkedAt !== null) {
            $age = time() - (int) strtotime($checkedAt);

            if ($age >= 0 && $age < $maxAgeSeconds) {
                return;
            }
        }

        $this->refresh();
    }

    /**
     * Release notes for a version, as raw Markdown, or null when unavailable.
     *
     * Fetched rather than stored: notes are read on one admin screen, and
     * keeping every release's prose in a settings row would grow a column that
     * nothing else needs. Failure is silent — a missing note must not stop the
     * page that reports the version from rendering.
     */
    public function fetchNotes(string $version, ?string $locale = null): ?string
    {
        if (preg_match('/^\d+(\.\d+){1,3}$/', $version) !== 1) {
            return null;
        }

        foreach ($this->noteUrls($version, $locale) as $url) {
            $body = $this->fetchNoteBody($url);

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Candidate note URLs, most specific first.
     *
     * The repository convention is that `<version>.md` is English and the
     * default, with translations alongside it as `<version>-<locale>.md`. So a
     * Turkish panel asks for `1.1.1-tr.md` and falls back to `1.1.1.md` when
     * that release was never translated — which is the common case for an old
     * release, and must show English prose rather than nothing.
     *
     * A regional locale contributes two candidates ("tr_TR" → tr_TR, then tr),
     * because a translation is far more likely to be filed under the language
     * than under the region.
     *
     * @return list<string>
     */
    private function noteUrls(string $version, ?string $locale): array
    {
        $base = 'https://raw.githubusercontent.com/CPalius/version/main/releases/';
        $urls = [];

        foreach ($this->localeCandidates($locale) as $candidate) {
            $urls[] = $base.$version.'-'.$candidate.'.md';
        }

        // The default file is always the last resort and always English.
        $urls[] = $base.$version.'.md';

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function localeCandidates(?string $locale): array
    {
        $locale = trim((string) $locale);

        // This value ends up in a URL. Anything that is not plainly a locale is
        // dropped rather than escaped: a locale is a short, well-shaped token,
        // and there is no legitimate input this rejects.
        if (preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,4})?$/', $locale) !== 1) {
            return [];
        }

        // English is the default file; asking for "1.1.1-en.md" first would mean
        // a pointless 404 on every English panel.
        if (str_starts_with($locale, 'en')) {
            return [];
        }

        $candidates = [$locale];

        if (str_contains($locale, '_')) {
            $candidates[] = substr($locale, 0, (int) strpos($locale, '_'));
        }

        return $candidates;
    }

    private function fetchNoteBody(string $url): ?string
    {
        try {
            $response = $this->client()->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $body = $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        return \strlen($body) > self::MAX_NOTES_BYTES ? null : $body;
    }

    /**
     * Fetches the pointer file and stores the result. Called from cron.
     *
     * Never throws: a failed check is not a failed cron run. Returns a short
     * line for the cron log.
     */
    public function refresh(): string
    {
        try {
            $payload = $this->fetch();
        } catch (\Throwable $e) {
            // The previous result is deliberately left in place. A network
            // blip must not erase a known-good "update available" notice.
            return 'Release check failed, keeping last known state: '.$e->getMessage();
        }

        $this->persist($payload);

        $latest = $payload['version'];

        return version_compare($latest, CpVersion::VERSION, '>')
            ? \sprintf('Update available: %s (running %s).', $latest, CpVersion::VERSION)
            : \sprintf('Up to date (%s).', CpVersion::VERSION);
    }

    private function client(): \Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return HttpClient::create([
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'headers' => [
                'User-Agent' => 'CPalius/'.CpVersion::VERSION.' (+https://www.cpalius.com)',
            ],
        ]);
    }

    /**
     * @return array{version: string, released_at: ?string, critical: bool, notes_url: ?string, notes_raw: ?string, download_zip: ?string, download_sha256: ?string}
     */
    private function fetch(): array
    {
        try {
            $response = $this->client()->request('GET', self::SOURCE);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new \RuntimeException('HTTP '.$status);
            }

            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (\strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('Payload too large');
        }

        $decoded = json_decode($body, true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('Malformed JSON');
        }

        $version = $decoded['version'] ?? null;

        // A pointer whose version does not parse would sort wrong against the
        // running version and could announce a downgrade as an update. Reject
        // it rather than store it.
        if (!\is_string($version) || preg_match('/^\d+(\.\d+){1,3}$/', $version) !== 1) {
            throw new \RuntimeException('Unusable version value');
        }

        $notes = $decoded['notes'] ?? null;
        $notesUrl = \is_array($notes) && \is_string($notes['url'] ?? null) ? $notes['url'] : null;
        $notesRaw = \is_array($notes) && \is_string($notes['raw'] ?? null) ? $notes['raw'] : null;

        $download = $decoded['download'] ?? null;
        $zip = \is_array($download) && \is_string($download['zip'] ?? null) ? $download['zip'] : null;
        $sha = \is_array($download) && \is_string($download['sha256'] ?? null) ? $download['sha256'] : null;

        // Everything below is remote input that ends up either as a link in the
        // admin panel or as the source of code this installation will execute.
        // Anything that is not plainly ours is dropped rather than trusted.
        $notesUrl = $this->trustedUrl($notesUrl, ['https://github.com/CPalius/']);
        $notesRaw = $this->trustedUrl($notesRaw, ['https://raw.githubusercontent.com/CPalius/']);
        $zip = $this->trustedUrl($zip, [
            'https://github.com/CPalius/',
            'https://raw.githubusercontent.com/CPalius/',
            'https://www.cpalius.com/',
            'https://cpalius.com/',
        ]);

        // A digest that is not 64 hex characters cannot be a SHA-256, and an
        // archive URL without a usable digest is refused by the updater rather
        // than installed on trust.
        if ($sha !== null && preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
            $sha = null;
        }

        return [
            'version' => $version,
            'released_at' => \is_string($decoded['released_at'] ?? null) ? $decoded['released_at'] : null,
            'critical' => (bool) ($decoded['critical'] ?? false),
            'notes_url' => $notesUrl,
            'notes_raw' => $notesRaw,
            'download_zip' => $zip,
            'download_sha256' => $sha,
        ];
    }

    /**
     * @param list<string> $allowedPrefixes
     */
    private function trustedUrl(?string $url, array $allowedPrefixes): ?string
    {
        if ($url === null) {
            return null;
        }

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param array{version: string, released_at: ?string, critical: bool, notes_url: ?string, notes_raw: ?string, download_zip: ?string, download_sha256: ?string} $payload
     */
    private function persist(array $payload): void
    {
        $payload['checked_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $setting = $this->settings->findOneBy(['settingKey' => self::KEY]);

        if (!$setting instanceof Setting) {
            $setting = new Setting(self::KEY);
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue(json_encode($payload, \JSON_THROW_ON_ERROR));

        $this->entityManager->flush();

        // Invalidate the memo so a refresh() followed by status() in the same
        // request — which is exactly what the updates screen does — sees the
        // value it just wrote rather than the one it read on the way in.
        $this->memo = null;
    }
}
