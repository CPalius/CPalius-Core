<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lets a module own invitation codes. Core decides WHEN a code is needed
 * (account.invitation_mode); the gate decides whether a code is valid and what
 * consuming it means. With no gate registered, registration is unchanged.
 */
#[AutoconfigureTag('cpalius.account.registration_invitation_gate')]
interface RegistrationInvitationGate
{
    /** Whether this gate is active (an inactive gate never blocks registration). */
    public function isRequired(): bool;

    /** @return string|null null when the code is usable, otherwise a generic error (never reveal whether the code exists) */
    public function validate(string $code): ?string;

    /** Called once the new user has an id; stores whatever the module needs (sponsor link, used flag). */
    public function consume(string $code, User $newUser): void;
}
