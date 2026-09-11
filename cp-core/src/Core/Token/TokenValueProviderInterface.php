<?php

declare(strict_types=1);

namespace App\Core\Token;

/**
 * T2.4: one implementation per token type ("node", "term", "user", "site"), tagged
 * `cp.token_value_provider` (autoconfigured via App\Core\Token\TokenValueProviderInterface
 * in services.yaml — same `_instanceof` convention as ConfigProviderInterface). Unlike
 * Drupal's `hook_tokens()` (every module gets invoked for every token, on every request),
 * TokenReplacer dispatches directly to the ONE provider whose supports() matches — no
 * sprawling module-wide hook fan-out.
 */
interface TokenValueProviderInterface
{
    public function supports(string $type): bool;

    /**
     * @return string|null null means "$name is not a token this provider recognizes" —
     *                     the caller leaves the original `[type:name]` bracket literal
     *                     in place (visible, debuggable — never a silent empty string)
     */
    public function resolve(string $name, mixed $subject, ?string $arg): ?string;
}
