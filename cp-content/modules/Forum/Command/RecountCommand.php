<?php

declare(strict_types=1);

namespace Modules\Forum\Command;

use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Service\ForumStatsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'forum:recount',
    description: 'Rebuild forum topic/section/user/board counters with COUNT (maintenance only).',
)]
final class RecountCommand extends Command
{
    public function __construct(
        private readonly ForumStatsService $statsService,
        private readonly ForumSectionRepository $sectionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('section', null, InputOption::VALUE_REQUIRED, 'Recount a single section id (and its topics)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sectionId = $input->getOption('section');

        $only = null;
        if ($sectionId !== null && $sectionId !== '') {
            $only = $this->sectionRepository->find((int) $sectionId);
            if (!$only instanceof ForumSection) {
                $io->error(sprintf('Section %s not found.', (string) $sectionId));

                return Command::FAILURE;
            }
        }

        $count = $this->statsService->recountAll($only);
        $io->success(sprintf('Recounted %d section(s).', $count));

        return Command::SUCCESS;
    }
}
