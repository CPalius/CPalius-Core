<?php

declare(strict_types=1);

namespace App\Form\DTO;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User admin DTO; writes go through mapDtoToUser(). Password required on create only.
 * Roles come from RoleConfigManager checkbox choices.
 */
final class UserFormModel
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

    /**
     * Plain password; hashed in controller. Empty on edit keeps the existing password.
     */
    #[Assert\Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.')]
    public ?string $plainPassword = null;

    #[Assert\Length(max: 120, maxMessage: 'Ad en fazla {{ limit }} karakter olabilir.')]
    public ?string $firstName = null;

    #[Assert\Length(max: 120, maxMessage: 'Soyad en fazla {{ limit }} karakter olabilir.')]
    public ?string $lastName = null;

    #[Assert\Length(max: 120, maxMessage: 'Konum en fazla {{ limit }} karakter olabilir.')]
    public ?string $location = null;

    #[Assert\Length(max: 16)]
    public string $locale = '';

    /**
     * Nullable, not '' — TextareaType::reverseTransform() turns an empty
     * submitted textarea into null (Symfony's own behavior, TextareaType-
     * specific), and PropertyAccessor writes that straight to this property
     * with no setter to coerce it; a non-nullable string here throws
     * InvalidTypeException on any save with an empty bio. mapDtoToUser()
     * already casts through (string) before it reaches the entity.
     */
    public ?string $bio = null;

    #[Assert\Length(max: 120)]
    public ?string $customTitle = null;

    public string $customTitleColor = '#64748b';

    public string $customTitleStyle = 'plain';

    public ?string $customTitleIcon = null;

    #[Assert\Choice(
        choices: [User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_BANNED],
        message: 'Invalid account status.',
    )]
    public string $status = User::STATUS_ACTIVE;

    /**
     * Selected CPalius role ids from the form checkbox list.
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
        $dto->location = $user->getLocation() !== '' ? $user->getLocation() : null;
        $dto->bio = $user->getBio();
        $dto->customTitle = $user->getCustomTitle() !== '' ? $user->getCustomTitle() : null;
        $dto->customTitleColor = $user->getCustomTitleColor() !== '' ? $user->getCustomTitleColor() : '#64748b';
        $dto->customTitleStyle = $user->getCustomTitleStyle();
        $dto->customTitleIcon = $user->getCustomTitleIcon() !== '' ? $user->getCustomTitleIcon() : null;
        $dto->status = $user->getStatus();
        $dto->roles = $user->getCpaliusRoles();

        return $dto;
    }
}
