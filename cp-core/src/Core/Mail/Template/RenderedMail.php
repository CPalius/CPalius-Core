<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

/**
 * A mail body resolved down to the three strings CpMailerService needs, plus
 * the locale it was actually rendered in (which is not always the one asked
 * for — see MailTemplateRenderer's fallback chain).
 */
final class RenderedMail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $locale,
        public readonly bool $customised,
    ) {
    }
}
