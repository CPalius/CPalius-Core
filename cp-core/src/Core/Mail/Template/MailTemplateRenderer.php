<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use App\Core\Localization\LocaleProvider;
use App\Core\Mail\Entity\MailTemplate;
use App\Core\Mail\Repository\MailTemplateRepository;
use App\Core\Token\TokenReplacer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns (template key, locale) into a finished subject and body.
 *
 * The fallback chain, in order, and why each step is there:
 *
 *   1. the operator's row for that exact locale   — what the screen is for
 *   2. the operator's row for the default locale  — a site that translated only
 *      its own language still mails its own wording rather than the shipped one
 *   3. the shipped translation catalogue           — always present, always tested
 *
 * Operator text is NEVER passed through the translator. The catalogue domain is
 * `messages+intl-icu`, and ICU treats a stray `{` as a syntax error: one
 * unbalanced brace typed into a textarea would turn every future registration
 * mail into an exception instead of a mail. Operator rows therefore get plain
 * `strtr()` placeholder substitution, which cannot fail, and the translator is
 * used only for strings this project wrote and tests cover.
 */
final class MailTemplateRenderer
{
    private const DOMAIN = 'messages';

    public function __construct(
        private readonly MailTemplateRegistry $registry,
        private readonly MailTemplateRepository $templates,
        private readonly TranslatorInterface $translator,
        private readonly TokenReplacer $tokenReplacer,
        private readonly LocaleProvider $localeProvider,
        private readonly MailTemplateSchema $schema,
    ) {
    }

    /**
     * @param array<string, string|int> $parameters   ICU/placeholder values declared by the definition
     * @param array<string, mixed>      $tokenContext token type => subject, for `[user:mail]` and friends
     *
     * @throws \InvalidArgumentException when the key was never declared by a provider
     */
    public function render(
        string $key,
        ?string $locale,
        array $parameters = [],
        array $tokenContext = [],
    ): RenderedMail {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Unknown mail template "%s".', $key));
        }

        $requested = $this->localeProvider->resolve($locale);
        $row = $this->findRow($key, $requested);
        $usedLocale = $requested;

        if ($row === null) {
            $default = $this->localeProvider->getDefaultCode();

            if ($default !== $requested) {
                $row = $this->findRow($key, $default);

                if ($row !== null) {
                    $usedLocale = $default;
                }
            }
        }

        $stringParams = $this->stringify($parameters);

        if ($row !== null) {
            $subject = $this->substitute($row->getSubject(), $stringParams);
            $html = $this->substitute($row->getBodyHtml(), $stringParams);
            $text = $this->substitute((string) $row->getBodyText(), $stringParams);

            return new RenderedMail(
                subject: $this->tokens($subject, $tokenContext, escape: true),
                html: $this->tokens($html, $tokenContext, escape: true),
                text: $this->tokens($text !== '' ? $text : self::htmlToText($html), $tokenContext, escape: false),
                locale: $usedLocale,
                customised: true,
            );
        }

        $subject = $this->translate($definition->subjectKey, $stringParams, $requested);
        $html = $this->translate($definition->htmlKey, $stringParams, $requested);
        $text = $definition->textKey !== null
            ? $this->translate($definition->textKey, $stringParams, $requested)
            : self::htmlToText($html);

        return new RenderedMail(
            subject: $this->tokens($subject, $tokenContext, escape: true),
            html: $this->tokens($html, $tokenContext, escape: true),
            text: $this->tokens($text, $tokenContext, escape: false),
            locale: $requested,
            customised: false,
        );
    }

    /**
     * The shipped default for one locale, for the "reset" button and for
     * pre-filling an empty editor. Never reads the database.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function shippedDefaults(MailTemplateDefinition $definition, string $locale): array
    {
        $placeholders = [];

        foreach ($definition->parameters as $parameter) {
            // Left as literal braces so the editor shows the operator exactly
            // what they may type, rather than a rendered example value.
            $placeholders[$parameter] = '{'.$parameter.'}';
        }

        $html = $this->translate($definition->htmlKey, $placeholders, $locale);

        return [
            'subject' => $this->translate($definition->subjectKey, $placeholders, $locale),
            'html' => $html,
            'text' => $definition->textKey !== null
                ? $this->translate($definition->textKey, $placeholders, $locale)
                : self::htmlToText($html),
        ];
    }

    /**
     * Readable plain-text alternative for a body that only ships as HTML.
     * Deliberately dumb: block tags become newlines, everything else is
     * dropped. A mail client that cannot read HTML still gets the sentences and
     * the link text.
     */
    public static function htmlToText(string $html): string
    {
        $withBreaks = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($collapsed);
    }

    /**
     * A row the operator disabled or emptied counts as absent, so the chain
     * continues to the default locale and then to the catalogue.
     */
    private function findRow(string $key, string $locale): ?MailTemplate
    {
        try {
            $row = $this->templates->findOneFor($key, $locale);
        } catch (\Throwable) {
            // Table not there yet (a file patch cannot run migrations) or the
            // DB is down. Create it if we can, and answer this mail from the
            // catalogue either way — Core Never Dies applies to the mail queue
            // too, and there is nothing in a table that was just created.
            $this->schema->ensure();

            return null;
        }

        if (!$row instanceof MailTemplate || !$row->isEnabled() || $row->isBlank()) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function substitute(string $subject, array $parameters): string
    {
        if ($parameters === []) {
            return $subject;
        }

        $pairs = [];

        foreach ($parameters as $name => $value) {
            $pairs['{'.$name.'}'] = $value;
        }

        return strtr($subject, $pairs);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function translate(string $key, array $parameters, string $locale): string
    {
        // Bare names, not `{name}`: the catalogue is `messages+intl-icu`, and the
        // ICU formatter matches placeholders by name. Brace-wrapped keys are the
        // legacy formatter's convention and would silently leave `{url}` in the
        // mail.
        return $this->translator->trans($key, $parameters, self::DOMAIN, $locale);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function tokens(string $value, array $context, bool $escape): string
    {
        return $value === '' ? '' : $this->tokenReplacer->replace($value, $context, $escape);
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array<string, string>
     */
    private function stringify(array $parameters): array
    {
        $out = [];

        foreach ($parameters as $name => $value) {
            $out[$name] = (string) $value;
        }

        return $out;
    }
}
