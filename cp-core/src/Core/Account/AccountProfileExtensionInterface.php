<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Extra account-profile fields owned by a module (never hardcoded in core).
 */
#[AutoconfigureTag('cpalius.account.profile_extension')]
interface AccountProfileExtensionInterface
{
    /**
     * @return array{id: string, legendKey: string, hintKey: ?string, fields: list<string>}
     */
    public function section(): array;

    public function buildForm(FormBuilderInterface $builder): void;

    /**
     * @return array<string, mixed>
     */
    public function valuesFromUser(User $user): array;

    public function saveToUser(User $user, FormInterface $form): void;
}
