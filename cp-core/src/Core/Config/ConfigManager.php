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

                    $changes = $provider->importDocument($document, $this->files->read($document));
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
