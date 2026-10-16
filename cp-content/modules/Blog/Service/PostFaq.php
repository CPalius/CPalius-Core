<?php

declare(strict_types=1);

namespace Modules\Blog\Service;

/**
 * The "frequently asked questions" of a post: question/answer pairs that the theme prints under the
 * article and the SEO module repeats as a FAQPage. Authored as plain text, one block per question —
 * first line the question, the lines after it the answer, a blank line between blocks:
 *
 *   What is X?
 *   X is …
 *
 *   How do I Y?
 *   You …
 *
 * Plain text only (tags stripped): the answer is also placed in JSON-LD, where markup is not allowed.
 */
final class PostFaq
{
    public const MAX_ITEMS = 20;

    private const MAX_QUESTION = 300;

    private const MAX_ANSWER = 2000;

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function parse(?string $text): array
    {
        $items = [];
        foreach (preg_split('/\R{2,}/', trim((string) $text)) ?: [] as $block) {
            $lines = preg_split('/\R/', trim($block)) ?: [];
            $question = self::clean((string) array_shift($lines), self::MAX_QUESTION);
            $answer = self::clean(implode(' ', $lines), self::MAX_ANSWER);
            if ($question === '' || $answer === '') {
                continue;
            }
            $items[] = ['question' => $question, 'answer' => $answer];
            if (\count($items) === self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param mixed $items the value stored in the node data
     */
    public static function format(mixed $items): string
    {
        if (!\is_array($items)) {
            return '';
        }

        $blocks = [];
        foreach (self::normalize($items) as $item) {
            $blocks[] = $item['question']."\n".$item['answer'];
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Stored data is untrusted shape-wise (imports, older rows): keep only well-formed pairs.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function normalize(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (\is_array($item) && \is_string($item['question'] ?? null) && \is_string($item['answer'] ?? null)
                && trim($item['question']) !== '' && trim($item['answer']) !== '') {
                $clean[] = ['question' => $item['question'], 'answer' => $item['answer']];
            }
        }

        return $clean;
    }

    private static function clean(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_substr($text, 0, $max);
    }
}
