<?php

declare(strict_types=1);

namespace App\Core\Token\Provider;

use App\Core\Token\TokenValueProviderInterface;
use App\Entity\User;

final class UserTokenProvider implements TokenValueProviderInterface
{
    public function supports(string $type): bool
    {
        return $type === 'user';
    }

    public function resolve(string $name, mixed $subject, ?string $arg): ?string
    {
        if (!$subject instanceof User) {
            return null;
        }

        return match ($name) {
            'mail' => $subject->getEmail(),
            'username' => $subject->getUsername() ?? '',
            'display_name' => $subject->getPublicDisplayName(),
            'full_name' => $subject->getFullName(),
            'profile_slug' => $subject->getProfileSlug(),
            'created' => $this->date($subject->getCreatedAt(), $arg),
            default => $this->fieldValue($subject, $name),
        };
    }

    private function date(\DateTimeInterface $date, ?string $format): string
    {
        return $date->format($format !== null && $format !== '' ? $format : 'Y-m-d');
    }

    private function fieldValue(User $user, string $name): ?string
    {
        $value = $user->getDataValue($name);

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            default => null,
        };
    }
}
