<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * Fail-closed rules for machine capabilities. "*" is never a valid key grant.
 */
final class ApiCapabilityPolicy
{
    public const PATTERN = '/^[a-z][a-z0-9_.]{0,99}$/';

    /**
     * @param list<string> $capabilities
     *
     * @return list<string>
     */
    public function sanitizeGrants(array $capabilities): array
    {
        $clean = [];
        foreach ($capabilities as $capability) {
            $capability = strtolower(trim($capability));
            if ($capability === '' || $capability === '*') {
                continue;
            }
            if (preg_match(self::PATTERN, $capability) !== 1) {
                continue;
            }
            $clean[] = $capability;
            if (\count($clean) >= ApiKey::MAX_CAPABILITIES) {
                break;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Replace {param} tokens with captured path values. Unknown or unsafe tokens fail closed (null).
     *
     * @param array<string, string> $pathParameters name => value
     */
    public function interpolate(?string $template, array $pathParameters): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }

        $resolved = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use ($pathParameters): string {
                $value = $pathParameters[$m[1]] ?? '';
                if ($value === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $value) !== 1) {
                    return "\0";
                }

                return strtolower($value);
            },
            $template,
        );

        if (!\is_string($resolved) || str_contains($resolved, "\0")) {
            return null;
        }

        $resolved = strtolower($resolved);

        return preg_match(self::PATTERN, $resolved) === 1 ? $resolved : null;
    }
}
