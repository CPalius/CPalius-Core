<?php

declare(strict_types=1);

namespace App\Core\Security;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Human labels for capability strings. Missing catalogue entries fall back
 * to the machine name so an unknown module never blanks the AACP form.
 */
final class CapabilityLabeler
{
    public const DOMAIN = 'capabilities';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function label(string $capability): string
    {
        $translated = $this->translator->trans($capability, [], self::DOMAIN);
        if ($translated !== $capability) {
            return $translated;
        }

        $fromMessages = $this->translator->trans('capability.'.$capability);
        if ($fromMessages !== 'capability.'.$capability) {
            return $fromMessages;
        }

        return $capability;
    }

    /**
     * @param list<string> $capabilities
     *
     * @return array<string, string>
     */
    public function map(array $capabilities): array
    {
        $labels = [];
        foreach ($capabilities as $capability) {
            $labels[$capability] = $this->label($capability);
        }

        return $labels;
    }
}
