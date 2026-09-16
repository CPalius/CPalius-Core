<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Optional subject of a conversation (a showcase listing, a forum profile, …).
 *
 * Stored as opaque strings so this module never imports Forum or Showcase.
 * The URL is re-checked before it is saved: only http(s) or a site-relative path.
 */
final readonly class MessagesContext
{
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public function __construct(
        public ?string $type,
        public ?int $id,
        public ?string $label,
        public ?string $url,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $type = strtolower(trim((string) $request->request->get('context_type', $request->query->get('context_type', ''))));
        if ($type === '' || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            $type = null;
        }

        $id = $request->request->getInt('context_id', $request->query->getInt('context_id'));
        if ($id <= 0) {
            $id = null;
        }

        $label = trim(strip_tags((string) $request->request->get('context_label', $request->query->get('context_label', ''))));
        $label = $label !== '' ? mb_substr($label, 0, 191) : null;

        $url = self::sanitizeUrl((string) $request->request->get('context_url', $request->query->get('context_url', '')));

        return new self($type, $id, $label, $url);
    }

    public static function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $url = str_replace(["\0", "\r", "\n", "\t"], '', $url);

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return mb_substr($url, 0, 500);
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return mb_substr($url, 0, 500);
    }
}
