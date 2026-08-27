<?php

declare(strict_types=1);

namespace App\Form\DTO;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * AACP "Profilim" ekranının form-katmanı DTO'su — giriş yapmış yöneticinin
 * KENDİ bilgilerini güncellediği, UserFormModel'den bilinçli olarak AYRI
 * bir DTO: burada 'status' ve 'roles' YOKTUR (bir kullanıcı kendi hesap
 * durumunu veya rollerini asla kendi profil formu üzerinden değiştiremez —
 * bu alanlar sadece system.users.manage yetkisiyle AACPUserController'ın
 * kullanıcı düzenleme ekranından değiştirilebilir).
 *
 * bio: CKEditor 5 (data-cpeditor) ile zenginleştirilir, form katmanında ham
 *   HTML taşır — sanitizasyon PostFormModel/mapDtoToNode desenindeki gibi
 *   controller'da (AACPUserController::mapProfileDtoToUser()) RichTextSanitizer
 *   ile yapılır (Manifesto Law 5.3).
 */
final class ProfileFormModel
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

    #[Assert\Length(max: 120, maxMessage: 'Ad en fazla {{ limit }} karakter olabilir.')]
    public ?string $firstName = null;

    #[Assert\Length(max: 120, maxMessage: 'Soyad en fazla {{ limit }} karakter olabilir.')]
    public ?string $lastName = null;

    public ?string $bio = null;

    public ?int $avatarAssetId = null;

    /**
     * Mevcut şifreyi değiştirmek için doldurulur; boşsa şifre değişmez.
     * AACPUserController, currentPassword ile birlikte doğrulanmadan
     * (UserPasswordHasherInterface::isPasswordValid()) bu alanı ASLA
     * User::setPassword()'a yazmaz (bkz. o metodun doküman notu).
     */
    #[Assert\Length(min: 8, minMessage: 'Şifre en az {{ limit }} karakter olmalıdır.')]
    public ?string $newPassword = null;

    /**
     * newPassword doldurulmuşsa zorunludur — bu koşullu kural form
     * seviyesinde değil, AACPUserController::updateProfile() içinde
     * değerlendirilir (PostFormModel'deki #[Assert\Callback] deseniyle
     * aynı "çalışma zamanı koşullu zorunluluk" felsefesi).
     */
    public ?string $currentPassword = null;

    public static function fromUser(User $user): self
    {
        $dto = new self();
        $dto->email = $user->getEmail();
        $dto->username = $user->getUsername();
        $dto->firstName = $user->getFirstName() ?: null;
        $dto->lastName = $user->getLastName() ?: null;
        $dto->bio = $user->getBio() ?: null;
        $dto->avatarAssetId = $user->getAvatarAssetId();

        return $dto;
    }
}
