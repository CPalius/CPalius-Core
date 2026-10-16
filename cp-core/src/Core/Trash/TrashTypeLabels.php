<?php

declare(strict_types=1);

namespace App\Core\Trash;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Human name of a recycle-bin type. The raw type is a class short name or a #[CpResource] name
 * ("BlogComment", "showcase_item"); screens show aacp.trash.type.<name> instead, and a type
 * nobody has translated yet falls back to its name split into words rather than showing code.
 */
final class TrashTypeLabels
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function label(string $name): string
    {
        $key = 'aacp.trash.type.'.$name;
        $translated = $this->translator->trans($key);
        if ($translated !== $key) {
            return $translated;
        }

        $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|[_\.]+/', ' ', $name) ?? $name;

        return ucfirst(trim($words));
    }

    /**
     * @param iterable<string> $names
     *
     * @return array<string, string> name => label
     */
    public function labels(iterable $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = $this->label($name);
        }

        return $out;
    }
}
