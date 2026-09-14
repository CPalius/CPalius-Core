<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Settings\SettingsRegistry;
use App\Core\Storage\StorageTargetRegistry;
use App\Core\Storage\TargetResolverInterface;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends finished backup archives off this server, and remembers which ones made it.
 *
 * A backup that lives on the same disk as the site is not a backup. It survives
 * a bad deploy and a dropped table; it does not survive the one event people
 * actually buy backups for, which is losing the server. So the whole point of
 * this class is the copy that leaves.
 *
 * Two decisions worth stating plainly:
 *
 * FAILURE IS LOUD. Everywhere else in CPalius a failed remote copy is a shrug —
 * media offload logs a warning and the origin serves the file. Here it is an
 * exception that reaches the operator's screen, because the whole value of the
 * feature is the operator's belief that it worked. An off-site backup that
 * quietly stopped running eleven months ago is worse than no backup at all: it
 * bought a year of not looking.
 *
 * THE LOCAL COPY IS DELETED LAST, AND ONLY ON PROOF. keep_local = false is a
 * legitimate choice on a 5 GB shared hosting plan, but "upload then delete" is
 * only safe if the upload is verified, so the object is read back before the
 * local file is removed. A truncated PUT that returned 200 would otherwise
 * destroy the only intact copy.
 *
 * The ledger lives in one settings row, like ReleaseChecker's: a table would
 * need a migration, and this has to work on an installation whose files are
 * newer than its schema.
 */
final class BackupShipper
{
    private const LEDGER_KEY = 'backup.remote.ledger';

    /** A ledger is bookkeeping, not an archive; refuse to let it grow unbounded. */
    private const LEDGER_MAX = 200;

    public function __construct(
        private readonly TargetResolverInterface $targets,
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_BACKUP) !== null;
    }

    /** What the operator selected, even when it is unusable — the screen has to explain the difference. */
    public function selectedType(): string
    {
        return $this->targets->selectedType(StorageTargetRegistry::PURPOSE_BACKUP);
    }

    public function targetLabel(): ?string
    {
        return $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_BACKUP)?->describe();
    }

    public function keepLocal(): bool
    {
        return (bool) $this->settings->get('storage.backup.keep_local', true);
    }

    /**
     * Uploads one archive and records it.
     *
     * @throws BackupException when a target is configured and the upload failed
     *
     * @return bool false when no target is configured (not an error)
     */
    public function ship(string $filename, string $localPath): bool
    {
        $target = $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_BACKUP);

        if ($target === null) {
            // Distinguishes "nothing to do" from "it did not work". Only the
            // second deserves to stop the operator.
            if ($this->selectedType() !== 'off') {
                throw new BackupException('The selected backup destination has not passed a connection test.');
            }

            return false;
        }

        if (!is_file($localPath)) {
            throw new BackupException(sprintf('Archive "%s" is not on disk.', $filename));
        }

        $key = $this->remoteKey($filename);

        try {
            $target->put($localPath, $key);

            // Read back before claiming success. A PUT that returned 200 for a
            // body the connection truncated is exactly the case that makes
            // "upload then delete local" dangerous.
            if (!$target->has($key)) {
                throw new BackupException('The archive was uploaded but is not readable back from the destination.');
            }
        } catch (BackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger?->error('Backup upload failed for {file}: {reason}', [
                'file' => $filename,
                'reason' => $e->getMessage(),
            ]);

            throw new BackupException(sprintf('Upload to %s failed: %s', $target->describe(), $e->getMessage()), 0, $e);
        }

        $this->remember($filename, [
            'target' => $target->type(),
            'key' => $key,
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'size' => (int) (filesize($localPath) ?: 0),
        ]);

        return true;
    }

    /**
     * Drops the remote copy and its ledger entry.
     *
     * Its own action, never a side effect of deleting the local archive. The
     * two copies exist precisely so that losing one is survivable, and an
     * operator clearing disk space on a cramped host must not discover that
     * "delete" also reached across the network and removed the copy that was
     * the entire point. The panel lists them as separate rows and asks
     * separately.
     */
    public function forget(string $filename): void
    {
        $entry = $this->ledger()[$filename] ?? null;

        if ($entry === null) {
            return;
        }

        $target = $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_BACKUP);

        if ($target !== null) {
            try {
                $target->delete((string) $entry['key']);
            } catch (\Throwable $e) {
                // The ledger entry is still removed below: an object that could
                // not be deleted is a cleanup problem, and keeping a row that
                // claims the backup exists here would be a correctness one.
                $this->logger?->warning('Remote backup delete failed for {file}: {reason}', [
                    'file' => $filename,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $ledger = $this->ledger();
        unset($ledger[$filename]);
        $this->writeLedger($ledger);
    }

    /**
     * Enforces remote retention, newest kept.
     *
     * Retention is applied after a successful upload rather than on a schedule,
     * so the count is only ever reduced at the moment there is a fresh copy to
     * replace what is being dropped.
     *
     * @return int number of remote archives removed
     */
    public function prune(): int
    {
        $keep = (int) $this->settings->get('storage.backup.remote_retention', 10);

        if ($keep < 1) {
            return 0;
        }

        $ledger = $this->ledger();

        if (count($ledger) <= $keep) {
            return 0;
        }

        uasort($ledger, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        $target = $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_BACKUP);
        $removed = 0;
        $index = 0;

        foreach ($ledger as $filename => $entry) {
            if ($index++ < $keep) {
                continue;
            }

            if ($target !== null) {
                try {
                    $target->delete((string) $entry['key']);
                } catch (\Throwable $e) {
                    $this->logger?->warning('Backup retention delete failed for {file}: {reason}', [
                        'file' => $filename,
                        'reason' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            unset($ledger[$filename]);
            ++$removed;
        }

        if ($removed > 0) {
            $this->writeLedger($ledger);
        }

        return $removed;
    }

    /**
     * filename => {target, key, at, size}
     *
     * @return array<string, array<string, mixed>>
     */
    public function ledger(): array
    {
        $raw = $this->settingRepository->findOneBy(['settingKey' => self::LEDGER_KEY])?->getSettingValue();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $ledger = [];

        foreach ($decoded as $filename => $entry) {
            if (is_string($filename) && is_array($entry) && BackupFilename::isValid($filename)) {
                $ledger[$filename] = $entry;
            }
        }

        return $ledger;
    }

    private function remoteKey(string $filename): string
    {
        $prefix = trim(str_replace('\\', '/', (string) $this->settings->get('storage.backup.prefix', 'cpalius-backups')), '/');

        return $prefix === '' ? $filename : $prefix.'/'.$filename;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function remember(string $filename, array $entry): void
    {
        $ledger = $this->ledger();
        $ledger[$filename] = $entry;

        if (count($ledger) > self::LEDGER_MAX) {
            uasort($ledger, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));
            $ledger = array_slice($ledger, 0, self::LEDGER_MAX, true);
        }

        $this->writeLedger($ledger);
    }

    /**
     * @param array<string, array<string, mixed>> $ledger
     */
    private function writeLedger(array $ledger): void
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => self::LEDGER_KEY]);

        if (!$setting instanceof Setting) {
            $setting = new Setting(self::LEDGER_KEY, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue(json_encode($ledger, JSON_THROW_ON_ERROR));
        $this->entityManager->flush();
    }
}
