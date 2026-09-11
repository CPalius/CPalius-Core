<?php

declare(strict_types=1);

namespace App\Core\Token;

/**
 * T2.4: generic `[type:property]` / `[type:property:arg]` replacement (`[node:title]`,
 * `[node:created:Y]`, `[user:mail]`, `[site:name]`). Dispatches straight to the provider that
 * claims the type — no per-request sweep across every module (Drupal's `hook_tokens()` weakness).
 * An unresolved token (unknown type/property, or no subject for that type in $context) is left
 * as the literal bracket text rather than silently becoming an empty string, so a broken pattern
 * is visible in the output instead of quietly losing content.
 */
final class TokenReplacer
{
    private const PATTERN = '/\[([a-z][a-z0-9_]*):([a-z][a-z0-9_]*)(?::([^\]]+))?\]/i';

    /**
     * @param iterable<TokenValueProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly TokenTypeRegistry $catalog,
    ) {
    }

    /**
     * @param array<string, mixed> $context token type => subject object (e.g. ['node' => $post])
     * @param bool                 $escapeHtml htmlspecialchars() each resolved value. Use this
     *                                        whenever the template is HTML (mail bodies, SEO,
     *                                        text-format token filter) so a title like
     *                                        `A < B` cannot break out of markup. Path aliases
     *                                        slugify afterwards and must pass false.
     */
    public function replace(string $template, array $context = [], bool $escapeHtml = false): string
    {
        $result = preg_replace_callback(
            self::PATTERN,
            function (array $matches) use ($context, $escapeHtml): string {
                $type = strtolower($matches[1]);
                $name = strtolower($matches[2]);
                $arg = $matches[3] ?? null;
                $subject = $context[$type] ?? null;

                foreach ($this->providers as $provider) {
                    if (!$provider->supports($type)) {
                        continue;
                    }

                    $value = $provider->resolve($name, $subject, $arg);
                    if ($value !== null) {
                        return $escapeHtml
                            ? htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                            : $value;
                    }
                }

                return $matches[0];
            },
            $template,
        );

        return $result ?? $template;
    }

    /**
     * Save-time validation: catches malformed bracket syntax and unknown TYPES (a closed,
     * compile-time-known set). Property names are not hard-validated — see TokenTypeRegistry.
     *
     * @return list<string> machine-readable problem codes, empty when the pattern is clean
     */
    public function validate(string $pattern): array
    {
        $errors = [];

        $withoutTokens = preg_replace(self::PATTERN, '', $pattern) ?? $pattern;
        if (str_contains($withoutTokens, '[') || str_contains($withoutTokens, ']')) {
            $errors[] = 'malformed_syntax';
        }

        if (preg_match_all(self::PATTERN, $pattern, $matches, \PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $type = strtolower($match[1]);
                if (!$this->catalog->hasType($type)) {
                    $errors[] = 'unknown_type:'.$type;
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
