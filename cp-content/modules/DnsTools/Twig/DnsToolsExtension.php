<?php

declare(strict_types=1);

namespace Modules\DnsTools\Twig;

use Modules\DnsTools\Catalog\ResultLabels;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DnsToolsExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dnstools_result_i18n', $this->resultI18n(...)),
        ];
    }

    /**
     * @return array{fields: array<string, string>, values: array<string, string>}
     */
    public function resultI18n(): array
    {
        $fields = [];
        foreach (ResultLabels::fields() as $key) {
            $fields[$key] = $this->translator->trans('dnstools.field.'.$key);
        }

        $values = [];
        foreach (ResultLabels::values() as $key) {
            $values[$key] = $this->translator->trans('dnstools.value.'.$key);
        }

        return ['fields' => $fields, 'values' => $values];
    }
}
