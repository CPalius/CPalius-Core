<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Api;

use App\Core\Api\ApiCapabilityPolicy;
use App\Core\Api\ApiKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed machine-capability rules: "*" is never a grant, templates that
 * resolve to an unsafe value collapse to null (deny).
 */
#[CoversClass(ApiCapabilityPolicy::class)]
final class ApiCapabilityPolicyTest extends TestCase
{
    private ApiCapabilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ApiCapabilityPolicy();
    }

    public function testSanitizeGrantsDropsWildcardAndInvalidEntries(): void
    {
        $result = $this->policy->sanitizeGrants([
            '*',
            'Invoice.Read',       // upper-cased -> normalised
            '  ticket.create  ',  // trimmed
            'has space',          // invalid
            'bad!char',           // invalid
            '',
            'invoice.read',       // duplicate of normalised entry
        ]);

        self::assertSame(['invoice.read', 'ticket.create'], $result);
    }

    public function testSanitizeGrantsIsCappedAtMaxCapabilities(): void
    {
        $input = [];
        for ($i = 0; $i < ApiKey::MAX_CAPABILITIES + 20; ++$i) {
            $input[] = 'cap.n'.$i;
        }

        $result = $this->policy->sanitizeGrants($input);

        self::assertLessThanOrEqual(ApiKey::MAX_CAPABILITIES, \count($result));
    }

    #[DataProvider('interpolateProvider')]
    public function testInterpolate(?string $template, array $params, ?string $expected): void
    {
        self::assertSame($expected, $this->policy->interpolate($template, $params));
    }

    /**
     * @return iterable<string, array{?string, array<string, string>, ?string}>
     */
    public static function interpolateProvider(): iterable
    {
        yield 'null template' => [null, [], null];
        yield 'empty template' => ['', [], null];
        yield 'no placeholder' => ['invoice.read', [], 'invoice.read'];
        yield 'resolved placeholder' => ['{name}.view', ['name' => 'invoice'], 'invoice.view'];
        yield 'placeholder lowercased' => ['{name}.view', ['name' => 'Invoice'], 'invoice.view'];
        yield 'missing param fails closed' => ['{name}.view', [], null];
        yield 'unsafe param value fails closed' => ['{name}.view', ['name' => 'a/b'], null];
        yield 'param with dot fails closed' => ['{name}.view', ['name' => 'a.b'], null];
    }
}
