<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumCensorWord;
use Modules\Forum\Repository\ForumCensorWordRepository;

final class ForumCensorService
{
    /** @var list<ForumCensorWord>|null */
    private ?array $words = null;

    public function __construct(
        private readonly ForumCensorWordRepository $wordRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.censor_enabled', true);
    }

    public function apply(string $html): string
    {
        if (!$this->isEnabled()) {
            return $html;
        }

        foreach ($this->words() as $row) {
            $word = $row->getWord();
            if ($word === '') {
                continue;
            }
            $replacement = $row->getReplacement() ?? str_repeat('*', max(3, mb_strlen($word)));
            $html = preg_replace('/'.preg_quote($word, '/').'/iu', $replacement, $html) ?? $html;
        }

        return $html;
    }

    public function add(string $word, ?string $replacement): ForumCensorWord
    {
        $entity = new ForumCensorWord($word, $replacement);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->words = null;

        return $entity;
    }

    public function remove(ForumCensorWord $word): void
    {
        $this->entityManager->remove($word);
        $this->entityManager->flush();
        $this->words = null;
    }

    /** @return list<ForumCensorWord> */
    public function all(): array
    {
        return $this->wordRepository->findAllOrdered();
    }

    /** @return list<ForumCensorWord> */
    private function words(): array
    {
        return $this->words ??= $this->wordRepository->findAllOrdered();
    }
}
