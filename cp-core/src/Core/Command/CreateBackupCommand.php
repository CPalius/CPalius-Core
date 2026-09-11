<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Backup\BackupException;
use App\Core\Backup\BackupFilename;
use App\Core\Backup\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'cp:backup:create',
    description: 'Creates a database, files, or full project backup under cp-core/var/backups/.',
)]
final class CreateBackupCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'type',
            InputArgument::REQUIRED,
            'Backup type: db, files, or full',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = strtolower(trim((string) $input->getArgument('type')));

        if (!\in_array($type, BackupFilename::TYPES, true)) {
            $io->error(sprintf('Type must be one of: %s.', implode(', ', BackupFilename::TYPES)));

            return Command::FAILURE;
        }

        try {
            $archive = $this->backupService->create($type);
        } catch (BackupException|Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Created %s (%s, %s).',
            $archive->filename,
            $archive->type,
            $archive->sizeLabel(),
        ));

        return Command::SUCCESS;
    }
}
