<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * CPalius'un e-posta VEYA kullanıcı adıyla giriş yapılabilen kullanıcı
 * sağlayıcısı (login formunda tek bir "Kullanıcı Adı veya E-posta" alanı
 * sunulmasını mümkün kılar).
 *
 * UserRepository zaten UserProviderInterface implement ediyor (Symfony'nin
 * "entity provider" kısayolu, bkz. UserRepository sınıf üstü doküman) ve
 * security.yaml'daki varsayılan "cpalius_users" provider'ı SADECE email
 * property'sine göre arama yapar. Bu sınıf, kullanıcı adı desteği İSTEYEN
 * kurulumlar için security.yaml'da provider olarak seçilebilecek AYRI,
 * opsiyonel bir alternatiftir — mevcut "cpalius_users" (entity provider)
 * davranışını DEĞİŞTİRMEZ, yanına eklenir.
 *
 * Fail-Safe: bulunamayan bir kullanıcı ya da pasif/banlı bir hesap için
 * UserNotFoundException fırlatılır — Symfony bunu firewall seviyesinde
 * "geçersiz kimlik bilgileri" olarak genelleştirip kullanıcıya hesabın var
 * olup olmadığını sızdırmaz (bkz. form_login'in varsayılan davranışı).
 */
final class CpUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneByEmailOrUsername($identifier);

        if ($user === null) {
            throw new UserNotFoundException(sprintf('"%s" kimlikli bir kullanıcı bulunamadı.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Geçersiz kullanıcı sınıfı "%s".', $user::class));
        }

        $freshUser = $this->userRepository->find($user->getId());

        if ($freshUser === null) {
            throw new UserNotFoundException(sprintf('#%d kimlikli kullanıcı artık mevcut değil.', $user->getId()));
        }

        return $freshUser;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->userRepository->upgradePassword($user, $newHashedPassword);
    }
}
