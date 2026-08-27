<?php

declare(strict_types=1);

namespace App\Form\DTO;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * AACPUserController'ın User entity'sine yazmadan önce kullandığı
 * form-katmanı DTO'su — PostFormModel (Modules\Blog\Form\DTO\PostFormModel)
 * ile AYNI felsefe: form katmanı Doctrine entity'sinden tamamen izole
 * tutulur, User'a yazım tek ve denetlenebilir bir noktada
 * (AACPUserController::mapDtoToUser()) yapılır.
 *
 * password: create ekranında zorunlu, edit ekranında BOŞ bırakılırsa şifre
 *   DEĞİŞTİRİLMEZ (mapDtoToUser() bu kuralı uygular) — bu yüzden burada
 *   sabit bir #[Assert\NotBlank] YOKTUR, zorunluluk controller seviyesinde
 *   "yeni kullanıcı mı" bilgisine göre değerlendirilir.
 * roles: RoleConfigManager::getAllRoleIds()'dan üretilen checkbox
 *   listesindeki seçili rol kimlikleri (ör. ["admin"], ["editor"]).
 */
final class UserFormModel
{
    #[Assert\NotBlank(message: 'E-posta zorunludur.')]
    #[Assert\Email(message: 'Geçerli bir e-posta adresi girin.')]
    #[Assert\Length(max: 180, maxMessage: 'E-posta en fazla {{ limit }} karakter olabilir.')]
    public string $email = '';

    #[Assert\Length(max: 180, maxMessage: 'Kullanıcı adı en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9_.-]*$/',
        message: 'Kullanıcı adı yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.',
    )]
    public ?string $username = null;

    /**
     * Düz metin şifre — asla persist edilmez, AACPUserController tarafından
     * UserPasswordHasherInterface ile hash'lendikten sonra User::setPassword()'a
     * yazılır. Boş bırakılırsa (edit ekranında) mevcut şifre korunur.
     */
    #[Assert\Length(min: 8, minMessage: 'Şifre en az {{ limit }} karakter olmalıdır.')]
    public ?string $plainPassword = null;

    #[Assert\Length(max: 120, maxMessage: 'Ad en fazla {{ limit }} karakter olabilir.')]
    public ?string $firstName = null;

    #[Assert\Length(max: 120, maxMessage: 'Soyad en fazla {{ limit }} karakter olabilir.')]
    public ?string $lastName = null;

    #[Assert\Choice(
        choices: [User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_BANNED],
        message: 'Geçersiz hesap durumu.',
    )]
    public string $status = User::STATUS_ACTIVE;

    /**
     * RoleConfigManager::getAllRoleIds() ile üretilen checkbox listesinden
     * seçilen CPalius rol kimlikleri (ör. ["admin", "editor"]). Geçersiz/
     * silinmiş bir rol kimliği burada gelse bile CPaliusVoter fail-safe
     * olarak onu sessizce yok sayar (bkz. RoleConfigManager doküman notu);
     * ek bir #[Assert\Choice] burada BİLİNÇLİ OLARAK yoktur çünkü UserType
     * checkbox seçeneklerini zaten RoleConfigManager'dan üretir — form
     * seviyesinde sunulmayan bir rol kimliği normal koşullarda gelmez.
     *
     * @var list<string>
     */
    public array $roles = [];

    public static function fromUser(User $user): self
    {
        $dto = new self();
        $dto->email = $user->getEmail();
        $dto->username = $user->getUsername();
        $dto->firstName = $user->getFirstName() ?: null;
        $dto->lastName = $user->getLastName() ?: null;
        $dto->status = $user->getStatus();
        $dto->roles = $user->getCpaliusRoles();

        return $dto;
    }
}
