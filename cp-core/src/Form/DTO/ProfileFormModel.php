<?php

declare(strict_types=1);

namespace App\Form\DTO;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Profile DTO without status/roles; bio HTML is sanitized in the controller (Law 5.3).
 */
final class ProfileFormModel
{
    #[Assert\NotBlank(message: 'E-posta zorunludur.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 180, maxMessage: 'E-posta en fazla {{ limit }} karakter olabilir.')]
    public string $email = '';

    #[Assert\Length(max: 180, maxMessage: 'Username may be at most {{ limit }} characters.')]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9_.-]*$/',
        message: 'Username may contain only letters, numbers, dots, hyphens, and underscores.',
    )]
    public ?string $username = null;

    #[Assert\Length(max: 120, maxMessage: 'Ad en fazla {{ limit }} karakter olabilir.')]
    public ?string $firstName = null;

    #[Assert\Length(max: 120, maxMessage: 'Soyad en fazla {{ limit }} karakter olabilir.')]
    public ?string $lastName = null;

    public ?string $bio = null;

    public ?int $avatarAssetId = null;

    /**
     * Optional new password; requires valid currentPassword before persisting.
     */
    #[Assert\Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.')]
    public ?string $newPassword = null;

    /**
     * Required when newPassword is set; validated in the controller, not via Assert.
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
