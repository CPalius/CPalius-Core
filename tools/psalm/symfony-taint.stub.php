<?php

/**
 * Psalm ships taint sources for PHP superglobals only. This project reads every
 * request through Symfony's HttpFoundation, so without these annotations taint
 * analysis reports a clean run while seeing none of the real input paths.
 */

namespace Symfony\Component\HttpFoundation;

class ParameterBag
{
    /** @psalm-taint-source input */
    public function all(?string $key = null): array {}

    /** @psalm-taint-source input */
    public function get(string $key, mixed $default = null): mixed {}

    /** @psalm-taint-source input */
    public function getString(string $key, string $default = ''): string {}

    /** @psalm-taint-source input */
    public function getAlpha(string $key, string $default = ''): string {}

    /** @psalm-taint-source input */
    public function getAlnum(string $key, string $default = ''): string {}

    /** @psalm-taint-source input */
    public function getDigits(string $key, string $default = ''): string {}
}

class InputBag extends ParameterBag
{
    /** @psalm-taint-source input */
    public function all(?string $key = null): array {}

    /** @psalm-taint-source input */
    public function get(string $key, mixed $default = null): mixed {}

    /** @psalm-taint-source input */
    public function getString(string $key, string $default = ''): string {}
}

class HeaderBag
{
    /** @psalm-taint-source input */
    public function all(?string $key = null): array {}

    /** @psalm-taint-source input */
    public function get(string $key, ?string $default = null): ?string {}
}

class Request
{
    /** @psalm-taint-source input */
    public function get(string $key, mixed $default = null): mixed {}

    /** @psalm-taint-source input */
    public function getContent(bool $asResource = false): string {}

    /** @psalm-taint-source input */
    public function getUri(): string {}

    /** @psalm-taint-source input */
    public function getRequestUri(): string {}

    /** @psalm-taint-source input */
    public function getPathInfo(): string {}

    /** @psalm-taint-source input */
    public function getQueryString(): ?string {}

    /** @psalm-taint-source input */
    public function getClientIp(): ?string {}
}
