<?php

declare(strict_types=1);

namespace App\Core\Token\Provider;

use App\Core\Taxonomy\Entity\Term;
use App\Core\Token\TokenValueProviderInterface;

final class TermTokenProvider implements TokenValueProviderInterface
{
    public function supports(string $type): bool
    {
        return $type === 'term';
    }

    public function resolve(string $name, mixed $subject, ?string $arg): ?string
    {
        if (!$subject instanceof Term) {
            return null;
        }

        return match ($name) {
            'name' => $subject->getName(),
            'slug' => $subject->getSlug(),
            'locale' => $subject->getLocale(),
            'created' => $this->date($subject->getCreatedAt(), $arg),
            'updated' => $this->date($subject->getUpdatedAt(), $arg),
            'parent' => $subject->getParent()?->getName() ?? '',
            default => $this->fieldValue($subject, $name),
        };
    }

    private function date(\DateTimeInterface $date, ?string $format): string
    {
        return $date->format($format !== null && $format !== '' ? $format : 'Y-m-d');
    }

    private function fieldValue(Term $term, string $name): ?string
    {
        $value = $term->getDataValue($name);

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            default => null,
        };
    }
}
