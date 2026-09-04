<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

/**
 * Quoted post ids from a reply body. The CKEditor quote button emits <blockquote data-post="N"> (forum.js).
 */
final class ForumQuoteParser
{
    /**
     * @return list<int>
     */
    public function extractQuotedPostIds(string $bodyHtml): array
    {
        $ids = [];

        if (preg_match_all('/<blockquote[^>]*\bdata-post=(?:"(\d+)"|\'(\d+)\'|(\d+))/i', $bodyHtml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $id = (int) ($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]));
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        // Fallback if RichTextSanitizer strips data-*: class="cp-quote-post-N".
        if (preg_match_all('/\bcp-quote-post-(\d+)\b/i', $bodyHtml, $classMatches)) {
            foreach ($classMatches[1] as $rawId) {
                $id = (int) $rawId;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        if (preg_match_all('/\[quote[^\]]*\bpost=(?:"(\d+)"|\'(\d+)\'|(\d+))/i', $bodyHtml, $bbMatches, PREG_SET_ORDER)) {
            foreach ($bbMatches as $match) {
                $id = (int) ($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]));
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
