<?php

namespace App\Core\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CPalius kurulumunun ilk adımı: veritabanında hiç kullanıcı yokken
 * AACP/admin paneline giriş yapabilecek ilk "admin" rolündeki kullanıcıyı
 * oluşturur. Şifre Symfony'nin auto hasher'ı (security.yaml'da 'auto' ->
 * Argon2id, sistem destekliyorsa) ile hashlenir; asla düz metin saklanmaz.
 */
#[AsCommand(
    name: 'cp:user:create-admin',
    description: 'İlk yönetici (admin) kullanıcısını oluşturur.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Yöneticinin e-posta adresi')
            ->addArgument('username', InputArgument::REQUIRED, 'Yöneticinin kullanıcı adı')
            ->addArgument('password', InputArgument::REQUIRED, 'Yöneticinin şifresi (düz metin, hash\'lenerek kaydedilir)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $username = (string) $input->getArgument('username');
        $password = (string) $input->getArgument('password');

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            $io->error(sprintf('"%s" e-postasına sahip bir kullanıcı zaten mevcut.', $email));

            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setDataValue('username', $username);
        $user->setCpaliusRoles(['admin']);
        $user->setStatus(User::STATUS_ACTIVE);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Yönetici kullanıcı oluşturuldu: %s (#%d)', $email, $user->getId()));

        return Command::SUCCESS;
    }
}
