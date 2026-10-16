<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Database\Type\EncryptedStringType;
use App\Core\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-seals every encrypted value under the current APP_SECRET.
 *
 * To replace APP_SECRET: put the old value in APP_SECRET_PREVIOUS, set the new one in APP_SECRET, run this, then
 * drop APP_SECRET_PREVIOUS. Until then both secrets work, so there is no moment at which stored secrets are
 * unreadable. A value that cannot be opened with any listed secret is reported and left untouched.
 */
#[AsCommand(name: 'cp:secrets:rotate', description: 'Re-seal encrypted settings, webhook secrets, 2FA secrets and encrypted columns under the current APP_SECRET.')]
final class SecretsRotateCommand extends Command
{
    private const SETTING_MARKER = 'cpenc:v1:';
    private const TWO_FACTOR_KEY = 'two_factor_secret';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $box,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be re-sealed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        if (\count($this->box->secrets()) < 2) {
            $io->note('APP_SECRET_PREVIOUS is empty: only values in an older format (v2 / CBC) will be upgraded.');
        }

        $total = ['rewritten' => 0, 'current' => 0, 'failed' => 0];
        $report = [];

        // cp_settings: "cpenc:v1:" + sealed value
        $report[] = ['cp_settings', $this->rotate(
            'cp_settings',
            'setting_key',
            'setting_value',
            $dry,
            static fn (string $v): ?string => str_starts_with($v, self::SETTING_MARKER) ? substr($v, \strlen(self::SETTING_MARKER)) : null,
            static fn (string $sealed): string => self::SETTING_MARKER.$sealed,
        )];

        $report[] = ['cp_webhook_subscriptions', $this->rotate(
            'cp_webhook_subscriptions',
            'id',
            'secret_cipher',
            $dry,
            static fn (string $v): ?string => $v,
            static fn (string $sealed): string => $sealed,
        )];

        $report[] = ['cp_users (2FA secret)', $this->rotateTwoFactor($dry)];

        foreach ($this->encryptedColumns() as [$table, $id, $column]) {
            $report[] = [$table.'.'.$column, $this->rotate(
                $table,
                $id,
                $column,
                $dry,
                static fn (string $v): ?string => str_starts_with($v, 'v3:') || str_starts_with($v, 'v2:') ? $v : null,
                static fn (string $sealed): string => $sealed,
            )];
        }

        foreach ($report as [$label, $r]) {
            foreach ($total as $k => $_) {
                $total[$k] += $r[$k];
            }
        }
        $io->table(['store', 're-sealed', 'already current', 'unreadable'], array_map(
            static fn (array $row): array => [$row[0], $row[1]['rewritten'], $row[1]['current'], $row[1]['failed']],
            $report,
        ));

        if ($total['failed'] > 0) {
            $io->error(sprintf('%d value(s) cannot be opened with APP_SECRET or APP_SECRET_PREVIOUS. Keep the old secret listed and restore the right one.', $total['failed']));

            return Command::FAILURE;
        }

        $io->success($dry
            ? sprintf('%d value(s) would be re-sealed.', $total['rewritten'])
            : sprintf('%d value(s) re-sealed. APP_SECRET_PREVIOUS can be removed.', $total['rewritten']));

        return Command::SUCCESS;
    }

    /**
     * @param \Closure(string): ?string $unwrap  returns the sealed part of a stored value, or null if it is not one
     * @param \Closure(string): string  $wrap
     *
     * @return array{rewritten: int, current: int, failed: int}
     */
    private function rotate(string $table, string $idColumn, string $column, bool $dry, \Closure $unwrap, \Closure $wrap): array
    {
        $out = ['rewritten' => 0, 'current' => 0, 'failed' => 0];
        $t = $this->connection->quoteSingleIdentifier($table);
        $i = $this->connection->quoteSingleIdentifier($idColumn);
        $c = $this->connection->quoteSingleIdentifier($column);

        try {
            $rows = $this->connection->fetchAllAssociative("SELECT $i AS k, $c AS v FROM $t WHERE $c IS NOT NULL AND $c <> ''");
        } catch (\Throwable) {
            return $out; // table absent on this install
        }

        foreach ($rows as $row) {
            $sealed = $unwrap((string) $row['v']);
            if ($sealed === null) {
                continue;
            }
            if (!$this->box->needsRewrap($sealed)) {
                ++$out['current'];

                continue;
            }

            try {
                $new = $wrap($this->box->seal($this->box->open($sealed)));
            } catch (\Throwable) {
                ++$out['failed'];

                continue;
            }

            if (!$dry) {
                $this->connection->executeStatement("UPDATE $t SET $c = :v WHERE $i = :k", ['v' => $new, 'k' => $row['k']]);
            }
            ++$out['rewritten'];
        }

        return $out;
    }

    /** @return array{rewritten: int, current: int, failed: int} */
    private function rotateTwoFactor(bool $dry): array
    {
        $out = ['rewritten' => 0, 'current' => 0, 'failed' => 0];

        try {
            $rows = $this->connection->fetchAllAssociative('SELECT id, data FROM cp_users WHERE data IS NOT NULL');
        } catch (\Throwable) {
            return $out;
        }

        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            $sealed = \is_array($data) ? ($data[self::TWO_FACTOR_KEY] ?? null) : null;
            if (!\is_string($sealed) || $sealed === '') {
                continue;
            }
            if (!$this->box->needsRewrap($sealed)) {
                ++$out['current'];

                continue;
            }

            try {
                $data[self::TWO_FACTOR_KEY] = $this->box->seal($this->box->open($sealed));
            } catch (\Throwable) {
                ++$out['failed'];

                continue;
            }

            if (!$dry) {
                $this->connection->executeStatement(
                    'UPDATE cp_users SET data = :d WHERE id = :id',
                    ['d' => json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), 'id' => $row['id']],
                );
            }
            ++$out['rewritten'];
        }

        return $out;
    }

    /** @return list<array{string, string, string}> table, id column, encrypted column */
    private function encryptedColumns(): array
    {
        $found = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $ids = $meta->getIdentifierColumnNames();
            if (\count($ids) !== 1) {
                continue;
            }
            foreach ($meta->fieldMappings as $mapping) {
                $type = \is_array($mapping) ? ($mapping['type'] ?? null) : $mapping->type;
                $column = \is_array($mapping) ? ($mapping['columnName'] ?? null) : $mapping->columnName;
                if ($type === EncryptedStringType::NAME && \is_string($column)) {
                    $found[] = [$meta->getTableName(), $ids[0], $column];
                }
            }
        }

        return $found;
    }
}
