<?php

declare(strict_types=1);

namespace App\Core\Token;

/**
 * Compile-time catalog of which token TYPES/PROPERTIES exist (core `tokens.yaml` + per-module
 * `Resources/config/tokens.yaml`, filled by TokenTypeRegistrationPass — same shape as
 * ViewModeRegistry). Two jobs: (1) a fast, browsable "available tokens" list for the AACP path
 * pattern screen (compiled, not a live hook-dispatch sweep like Drupal's Token module), (2) type
 * -level validation in TokenReplacer::validate() so a typo like `[nod:title]` is rejected when
 * an admin SAVES a pattern, not discovered later as a silently broken link.
 *
 * Deliberately does NOT enforce property names — `node`/`term`/`user` also resolve arbitrary
 * Field API / JSON data values by raw key (see NodeTokenProvider), and those cannot be known at
 * compile time. The catalog documents the common/hot-column properties; it is a reference, not
 * an allowlist.
 */
final class TokenTypeRegistry
{
    /** @var array<string, array<string, string>> type => property => label translation key */
    private array $types = [];

    public function register(string $type, string $property, string $label): void
    {
        $this->types[$type][$property] = $label;
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @return array<string, string> property => label translation key
     */
    public function propertiesFor(string $type): array
    {
        return $this->types[$type] ?? [];
    }

    /**
     * @return array<string, array<string, string>> type => property => label translation key
     */
    public function all(): array
    {
        return $this->types;
    }
}
