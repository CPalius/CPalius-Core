<?php

declare(strict_types=1);

namespace App\Form\DTO;

use App\Entity\User;

/**
 * Site-wide account profile — avatar, password, basic identity.
 * Module notification prefs are attached via AccountProfileExtensionInterface.
 */
final class AccountProfileFormModel
{
    public string $email = '';
    public ?string $username = null;
    public string $firstName = '';
    public string $lastName = '';
    public ?string $currentPassword = null;
    public ?string $newPassword = null;

    public static function fromUser(User $user): self
    {
        $dto = new self();
        $dto->email = $user->getEmail();
        $dto->username = $user->getUsername();
        $dto->firstName = $user->getFirstName();
        $dto->lastName = $user->getLastName();

        return $dto;
    }
}
