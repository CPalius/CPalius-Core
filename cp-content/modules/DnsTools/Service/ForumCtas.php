<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

final class ForumCtas
{
    public function __construct(
        private readonly ForumBridge $forum,
        private readonly CommunityHints $hints,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array{enabled: bool, lead: string, share: ?array{href: string, label: string}, hints: list<array{key: string, label: string, href: string}>}
     */
    public function for(string $slug, string $toolTitle, string $target, array $result): array
    {
        if (!$this->forum->enabled()) {
            return ['enabled' => false, 'lead' => '', 'share' => null, 'hints' => []];
        }

        $shareTitle = trim($toolTitle.($target !== '' ? ': '.$target : ''));
        $shareHref = $this->forum->wizardUrl($shareTitle) ?? $this->forum->searchHref($shareTitle);

        $hints = [];
        foreach ($this->hints->for($slug, $result) as $hint) {
            $title = trim($hint['query'].($target !== '' ? ' — '.$target : ''));
            $href = $this->forum->wizardUrl($title) ?? $this->forum->searchHref($title);
            if ($href === null) {
                continue;
            }
            $hints[] = [
                'key' => $hint['key'],
                'label' => $hint['label'],
                'title' => $title,
                'href' => $href,
            ];
        }

        return [
            'enabled' => true,
            'lead' => $this->translator->trans('dnstools.forum.lead'),
            'share' => $shareHref !== null ? [
                'href' => $shareHref,
                'title' => $shareTitle,
                'label' => $this->translator->trans('dnstools.forum.share'),
            ] : null,
            'hints' => $hints,
        ];
    }
}
