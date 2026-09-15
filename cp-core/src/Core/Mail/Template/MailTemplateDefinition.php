<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

/**
 * One kind of mail the platform can send, described once.
 *
 * The definition is what makes the screen possible: it names the shipped
 * default (translation keys), the ICU placeholders the sending code passes in,
 * and the `[type:property]` tokens TokenReplacer can resolve. Without it the
 * operator would be editing text with no way of knowing which `{url}` the code
 * actually supplies, and a typo would surface as a mail with a literal brace in
 * it to a real user.
 *
 * Declared by code (core or a module), never by a row: a template nobody sends
 * has no business appearing on the screen.
 */
final class MailTemplateDefinition
{
    /**
     * @param string       $key           registry key, e.g. "account.verify"
     * @param string       $labelKey      translation key for the screen title
     * @param string       $descriptionKey translation key explaining when it is sent
     * @param string       $subjectKey    translation key of the shipped subject
     * @param string       $htmlKey       translation key of the shipped HTML body
     * @param ?string      $textKey       translation key of the shipped text body, when one ships
     * @param list<string> $parameters    ICU placeholders the sender passes, without braces
     * @param list<string> $tokens        `[type:property]` tokens that resolve here
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly string $descriptionKey,
        public readonly string $subjectKey,
        public readonly string $htmlKey,
        public readonly ?string $textKey = null,
        public readonly array $parameters = [],
        public readonly array $tokens = ['site:name', 'site:url', 'user:display_name', 'user:mail'],
    ) {
    }
}
