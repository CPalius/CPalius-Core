<?php

declare(strict_types=1);

namespace App\Core\Config;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrates config export / diff / import across all ConfigProviders.
 * Import runs in one DB transaction; providers persist but never flush.
 */
final class ConfigManager
{
    private const HASH_KEY = 'config.release_hashes';

    /**
     * @param iterable<ConfigProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly ConfigFile $files,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string> document names written
     */
    public function export(): array
    {
        $written = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->documents() as $document) {
                $this->files->write($document, $provider->exportDocument($document));
                $written[] = $document;
            }
        }
        sort($written);

        return $written;
    }

    /**
     * @return array<string, array{status: string, changes: list<string>}>
     */
    public function status(): array
    {
        $result = [];
        $onDisk = $this->files->listOnDisk();

        foreach ($this->providers as $provider) {
            foreach ($this->documentsFor($provider, $onDisk) as $document) {
                if (isset($result[$document])) {
                    continue;
                }

                if (!$this->files->exists($document)) {
                    $result[$document] = ['status' => 'only-live', 'changes' => []];

                    continue;
                }

                $changes = $provider->diffDocument($document, $this->files->read($document));
                $isLive = \in_array($document, $provider->documents(), true);
                $result[$document] = [
                    'status' => $changes === [] ? 'in-sync' : ($isLive ? 'drifted' : 'new'),
                    'changes' => $changes,
                ];
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @param list<string>|null $only limit to these document names
     *
     * @return array<string, list<string>> document => applied change descriptions
     */
    public function import(?array $only = null): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $applied = [];
            /** @var array<int, ConfigProviderInterface> $touched */
            $touched = [];
            $onDisk = $this->files->listOnDisk();

            foreach ($this->providers as $provider) {
                foreach ($this->documentsFor($provider, $onDisk) as $document) {
                    if (($only !== null && !\in_array($document, $only, true)) || !$this->files->exists($document)) {
                        continue;
                    }

                    $incoming = $this->files->read($document);
                    // Same test status() uses: a document already equal to the
                    // live state is not re-imported (and not reported as updated).
                    if ($provider->diffDocument($document, $incoming) === []) {
                        continue;
                    }

                    $changes = $provider->importDocument($document, $incoming);
                    if ($changes !== []) {
                        $applied[$document] = $changes;
                        $touched[spl_object_id($provider)] = $provider;
                    }
                }
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        foreach ($touched as $provider) {
            $provider->afterImport();
        }

        return $applied;
    }

    /**
     * What an UPDATE may apply: documents the release changed since the last update applied them.
     *
     * Live state wins otherwise. Roles, whitepaper text and the like are edited in the panel; importing every
     * drifted file on each update put the shipped copy back over those edits. The first run only records a
     * baseline (and still creates documents that do not exist live yet).
     *
     * @return array<string, list<string>> document => changes it would make
     */
    public function releaseChanges(): array
    {
        $recorded = $this->recordedHashes();
        $out = [];

        foreach ($this->status() as $document => $info) {
            if ($info['changes'] === [] || $info['status'] === 'only-live') {
                continue;
            }

            if ($recorded === null ? $info['status'] === 'new' : ($recorded[$document] ?? null) !== $this->hashOf($document)) {
                $out[$document] = $info['changes'];
            }
        }

        return $out;
    }

    /**
     * Imports releaseChanges() and remembers the shipped files, so the next update only reacts to files that change again.
     *
     * @return array<string, list<string>> document => applied change descriptions
     */
    public function importRelease(): array
    {
        $applied = [];
        $due = array_keys($this->releaseChanges());

        if ($due !== []) {
            $applied = $this->import($due);
        }

        $this->recordHashes();

        return $applied;
    }

    private function hashOf(string $document): string
    {
        return sha1((string) json_encode($this->files->read($document)));
    }

    /**
     * @return array<string, string>|null null before the first update recorded anything
     */
    private function recordedHashes(): ?array
    {
        try {
            $raw = $this->entityManager->getConnection()->fetchOne('SELECT setting_value FROM cp_settings WHERE setting_key = ?', [self::HASH_KEY]);
        } catch (\Throwable) {
            return null;
        }

        $decoded = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($decoded) ? $decoded : null;
    }

    private function recordHashes(): void
    {
        $hashes = [];
        foreach ($this->files->listOnDisk() as $document) {
            $hashes[$document] = $this->hashOf($document);
        }

        $json = (string) json_encode($hashes);
        $connection = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            if ($connection->fetchOne('SELECT id FROM cp_settings WHERE setting_key = ?', [self::HASH_KEY]) === false) {
                $connection->executeStatement('INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (?, ?, ?, ?)', [self::HASH_KEY, $json, 'config', $now]);
            } else {
                $connection->executeStatement('UPDATE cp_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?', [$json, $now, self::HASH_KEY]);
            }
        } catch (\Throwable) {
            // Without the marker the next update behaves like the first one: it only creates what is missing.
        }
    }

    /**
     * @param list<string> $onDisk
     *
     * @return list<string>
     */
    private function documentsFor(ConfigProviderInterface $provider, array $onDisk): array
    {
        return array_values(array_unique(array_merge(
            $provider->documents(),
            array_filter($onDisk, $provider->ownsDocument(...)),
        )));
    }
}
