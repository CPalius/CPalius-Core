<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSmilie;
use Modules\Forum\Repository\ForumSmilieRepository;

/**
 * The smilie set the composer and the post body share.
 *
 * XenForo (and MyBB) keep the trigger in the stored post and swap it for a
 * picture at render time. Doing the same here means an imported ":)" lights
 * up without rewriting every row, and a new post typed with the picker
 * still works when somebody later edits the set.
 *
 * Built-in Unicode mappings cover the codes XenForo ships by default, so a
 * board that has not imported xf_smilie yet is not a wall of raw ":D".
 * An imported image for the same code wins.
 */
final class ForumSmilieCatalog
{
    /**
     * @var list<array{codes: list<string>, title: string, emoji: string}>
     */
    private const DEFAULTS = [
        ['codes' => [':)', ':-)'], 'title' => 'Smile', 'emoji' => '😊'],
        ['codes' => [':(', ':-('], 'title' => 'Frown', 'emoji' => '😞'],
        ['codes' => [':D', ':-D'], 'title' => 'Big grin', 'emoji' => '😃'],
        ['codes' => [':P', ':-P', ':p'], 'title' => 'Tongue', 'emoji' => '😛'],
        ['codes' => [';)', ';-)'], 'title' => 'Wink', 'emoji' => '😉'],
        ['codes' => [':o', ':-o', ':O'], 'title' => 'Surprised', 'emoji' => '😮'],
        ['codes' => [':|', ':-|'], 'title' => 'Neutral', 'emoji' => '😐'],
        ['codes' => [':cool:'], 'title' => 'Cool', 'emoji' => '😎'],
        ['codes' => [':mad:'], 'title' => 'Mad', 'emoji' => '😡'],
        ['codes' => [':confused:'], 'title' => 'Confused', 'emoji' => '😕'],
        ['codes' => [':rolleyes:'], 'title' => 'Roll eyes', 'emoji' => '🙄'],
        ['codes' => [':eek:'], 'title' => 'Eek', 'emoji' => '😱'],
        ['codes' => [':oops:'], 'title' => 'Oops', 'emoji' => '😳'],
        ['codes' => [':love:', '<3'], 'title' => 'Love', 'emoji' => '❤️'],
        ['codes' => [':thumbsup:', ':like:'], 'title' => 'Thumbs up', 'emoji' => '👍'],
        ['codes' => [':thumbsdown:'], 'title' => 'Thumbs down', 'emoji' => '👎'],
    ];

    /** @var list<array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor: bool}>|null */
    private ?array $entries = null;

    /** @var array<string, array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor: bool}> */
    private array $byCode = [];

    private ?string $pattern = null;

    public function __construct(
        private readonly ?ForumSmilieRepository $repository = null,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * @return list<array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor: bool}>
     */
    public function all(): array
    {
        $this->hydrate();

        return $this->entries ?? [];
    }

    /**
     * @return list<array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor: bool}>
     */
    public function forEditor(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $row): bool => $row['inEditor'] && ($row['image'] !== '' || $row['emoji'] !== ''),
        ));
    }

    public function replace(string $text): ?string
    {
        $this->hydrate();

        if ($this->pattern === null || $text === '' || preg_match($this->pattern, $text) !== 1) {
            return null;
        }

        return (string) preg_replace_callback($this->pattern, function (array $match): string {
            $entry = $this->byCode[$match[0]] ?? null;

            return $entry === null ? $match[0] : $this->markup($entry);
        }, $text);
    }

    /**
     * @param list<string> $codes
     */
    public static function defaultEmojiFor(array $codes): string
    {
        foreach (self::DEFAULTS as $row) {
            if (array_intersect($codes, $row['codes']) !== []) {
                return $row['emoji'];
            }
        }

        return '';
    }

    /**
     * @param array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor?: bool} $entry
     */
    public function markup(array $entry): string
    {
        $alt = htmlspecialchars($entry['codes'][0] ?? '', \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars($entry['title'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        if ($entry['image'] !== '') {
            $src = htmlspecialchars($entry['image'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

            return '<img class="forum-smilie" src="'.$src.'" alt="'.$alt.'" title="'.$title.'" width="22" height="22">';
        }

        if ($entry['emoji'] !== '') {
            return '<span class="forum-smilie forum-smilie--emoji" title="'.$title.'">'.$entry['emoji'].'</span>';
        }

        return $alt;
    }

    private function hydrate(): void
    {
        if ($this->entries !== null) {
            return;
        }

        try {
            if ($this->repository === null || $this->entityManager === null) {
                $this->entries = $this->defaultEntries();
                $this->index();

                return;
            }

            $rows = $this->repository->findAllOrdered();
            if ($rows === []) {
                $this->seedDefaults();
                $rows = $this->repository->findAllOrdered();
            }
            $this->entries = $this->fromEntities($rows);
        } catch (\Throwable) {
            $this->entries = $this->defaultEntries();
        }

        $this->index();
    }

    /**
     * @param list<ForumSmilie> $rows
     *
     * @return list<array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor: bool}>
     */
    private function fromEntities(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'codes' => $row->allCodes(),
                'title' => $row->getTitle(),
                'image' => (string) $row->getImageUrl(),
                'emoji' => (string) $row->getEmoji(),
                'category' => $row->getCategory(),
                'inEditor' => $row->isDisplayInEditor(),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{codes: list<string>, title: string, image: string, emoji: string, category: string, inEditor: bool}>
     */
    private function defaultEntries(): array
    {
        $out = [];

        foreach (self::DEFAULTS as $row) {
            $out[] = [
                'codes' => $row['codes'],
                'title' => $row['title'],
                'image' => '',
                'emoji' => $row['emoji'],
                'category' => 'default',
                'inEditor' => true,
            ];
        }

        return $out;
    }

    private function seedDefaults(): void
    {
        if ($this->entityManager === null) {
            return;
        }

        foreach (self::DEFAULTS as $i => $row) {
            $codes = $row['codes'];
            $smilie = new ForumSmilie($codes[0], $row['title']);
            $smilie->setExtraCodes(\array_slice($codes, 1));
            $smilie->setEmoji($row['emoji']);
            $smilie->setSortOrder($i * 10);
            $this->entityManager->persist($smilie);
        }

        $this->entityManager->flush();
    }

    private function index(): void
    {
        $this->byCode = [];
        $quoted = [];

        foreach ($this->entries ?? [] as $entry) {
            foreach ($entry['codes'] as $code) {
                if ($code === '') {
                    continue;
                }
                $this->byCode[$code] = $entry;
                $quoted[] = $code;
            }
        }

        usort($quoted, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $this->pattern = $quoted === []
            ? null
            : '/'.implode('|', array_map(static fn (string $c): string => preg_quote($c, '/'), $quoted)).'/u';
    }
}
