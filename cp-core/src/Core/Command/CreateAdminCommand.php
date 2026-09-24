<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Password\PasswordPolicy;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the first admin user for initial CPalius setup when the database has no users.
 * Password is hashed via Symfony auto hasher (Argon2id when supported); never stored in plain text.
 */
#[AsCommand(
    name: 'cp:user:create-admin',
    description: 'Creates the first administrator user.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordChanger $passwordChanger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Administrator email address')
            ->addArgument('username', InputArgument::REQUIRED, 'Administrator username')
            ->addArgument('password', InputArgument::REQUIRED, 'Administrator password (plain text; stored hashed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $username = (string) $input->getArgument('username');
        $password = (string) $input->getArgument('password');

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setDataValue('username', $username);

        $violations = $this->passwordPolicy->validate($password, [$email, $username]);
        if ($violations !== []) {
            $io->error('The password does not satisfy the site password policy.');
            $io->listing($violations);

            return Command::FAILURE;
        }

        $user->setCpaliusRoles(['admin']);
        $user->setStatus(User::STATUS_ACTIVE);
        $user->markEmailVerified();
        $this->passwordChanger->change($user, $password);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Administrator user created: %s (#%d)', $email, $user->getId()));

        return Command::SUCCESS;
    }
}
