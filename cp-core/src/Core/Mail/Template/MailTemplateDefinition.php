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
 * Declared by code (core or a module) for every mail the platform sends by
 * itself: a lifecycle template nobody sends has no business on the screen.
 *
 * The one exception is a custom template an operator created to send by hand
 * (see CustomMailTemplateProvider). It has no code behind it and therefore no
 * shipped catalogue, which is what $custom marks: with it set, the label,
 * description, subject and body fields hold literal operator text rather than
 * translation keys, and must never be handed to the translator.
 */
final class MailTemplateDefinition
{
    /**
     * @param string       $key           registry key, e.g. "account.verify"
     * @param string       $labelKey      translation key for the screen title — literal text when $custom
     * @param string       $descriptionKey translation key explaining when it is sent — literal text when $custom
     * @param string       $subjectKey    translation key of the shipped subject — literal fallback when $custom
     * @param string       $htmlKey       translation key of the shipped HTML body — literal fallback when $custom
     * @param ?string      $textKey       translation key of the shipped text body, when one ships
     * @param list<string> $parameters    ICU placeholders the sender passes, without braces
     * @param list<string> $tokens        `[type:property]` tokens that resolve here
     * @param bool         $custom        operator-created; no shipped catalogue stands behind it
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
        public readonly bool $custom = false,
    ) {
    }
}
