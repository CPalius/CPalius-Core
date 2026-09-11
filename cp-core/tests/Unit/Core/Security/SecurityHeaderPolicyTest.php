<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\EventListener\SecurityHeadersSubscriber;
use App\Core\Security\Http\CspNonceProvider;
use App\Core\Security\Http\SecurityHeaderPolicy;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(SecurityHeaderPolicy::class)]
#[CoversClass(CspNonceProvider::class)]
#[CoversClass(SecurityHeadersSubscriber::class)]
final class SecurityHeaderPolicyTest extends TestCase
{
    private const NONCE = 'AbCdEf0123456789';

    /**
     * @param array<string, mixed> $settings
     */
    private function policy(array $settings = []): SecurityHeaderPolicy
    {
        return new SecurityHeaderPolicy(new ArraySettings($settings));
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, string>
     */
    private function headers(array $settings, bool $secure = false, string $nonce = self::NONCE): array
    {
        $request = Request::create($secure ? 'https://example.test/tr' : 'http://example.test/tr');

        return $this->policy($settings)->headers($request, $nonce);
    }

    // --- modes -------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function modeSettings(): iterable
    {
        yield 'off' => ['off', SecurityHeaderPolicy::MODE_OFF];
        yield 'report' => ['report', SecurityHeaderPolicy::MODE_REPORT];
        yield 'balanced' => ['balanced', SecurityHeaderPolicy::MODE_BALANCED];
        yield 'strict' => ['strict', SecurityHeaderPolicy::MODE_STRICT];
        yield 'unset falls back to report' => [null, SecurityHeaderPolicy::MODE_REPORT];
        yield 'empty string falls back to report' => ['', SecurityHeaderPolicy::MODE_REPORT];
        yield 'typo falls back to report' => ['stric', SecurityHeaderPolicy::MODE_REPORT];
        yield 'uppercase is not a mode' => ['STRICT', SecurityHeaderPolicy::MODE_REPORT];
    }

    #[DataProvider('modeSettings')]
    public function testCspModeIsNormalized(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->policy(['security.csp_mode' => $stored])->cspMode());
    }

    public function testOffModeEmitsNoCspHeaderButKeepsTheBaselineHeaders(): void
    {
        $headers = $this->headers(['security.csp_mode' => 'off']);

        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
    }

    public function testReportModeUsesTheReportOnlyHeaderWithANonce(): void
    {
        $headers = $this->headers(['security.csp_mode' => 'report']);

        self::assertArrayHasKey('Content-Security-Policy-Report-Only', $headers);
        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
        self::assertStringContainsString("'nonce-".self::NONCE."'", $headers['Content-Security-Policy-Report-Only']);
        self::assertStringContainsString("'strict-dynamic'", $headers['Content-Security-Policy-Report-Only']);
    }

    public function testStrictModeEnforcesNonceAndStrictDynamic(): void
    {
        $headers = $this->headers(['security.csp_mode' => 'strict']);

        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers);

        $csp = $headers['Content-Security-Policy'];
        self::assertStringContainsString("script-src 'self' 'nonce-".self::NONCE."' 'strict-dynamic' https:", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringContainsString('report-uri '.SecurityHeaderPolicy::REPORT_PATH, $csp);
    }

    /**
     * A nonce and 'unsafe-inline' in the same source list make browsers ignore
     * 'unsafe-inline' entirely, which would silently break every theme the
     * balanced mode exists to keep working.
     */
    public function testBalancedModeEmitsUnsafeInlineAndNeverANonce(): void
    {
        $headers = $this->headers(['security.csp_mode' => 'balanced']);
        $csp = $headers['Content-Security-Policy'];

        self::assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        self::assertStringNotContainsString('nonce-', $csp);
        self::assertStringNotContainsString("'strict-dynamic'", $csp);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function nonceRequirement(): iterable
    {
        yield 'off needs no nonce' => ['off', false];
        yield 'report needs a nonce' => ['report', true];
        yield 'balanced must not have a nonce' => ['balanced', false];
        yield 'strict needs a nonce' => ['strict', true];
    }

    #[DataProvider('nonceRequirement')]
    public function testNonceRequired(string $mode, bool $expected): void
    {
        self::assertSame($expected, $this->policy(['security.csp_mode' => $mode])->nonceRequired());
    }

    /**
     * Regression: an empty nonce used to render as "'nonce-'", a malformed source
     * expression. Next to 'strict-dynamic' that rejects every script on the page —
     * the hardening layer taking the site down instead of the attacker.
     */
    public function testAnEmptyNonceDegradesToANonceLessPolicyInsteadOfKillingEveryScript(): void
    {
        foreach (['report', 'strict'] as $mode) {
            $headers = $this->headers(['security.csp_mode' => $mode], nonce: '');
            $csp = $headers[$mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'];

            self::assertStringNotContainsString("'nonce-'", $csp, $mode);
            self::assertStringNotContainsString('nonce-', $csp, $mode);
            self::assertStringNotContainsString("'strict-dynamic'", $csp, $mode);
            self::assertStringContainsString("script-src 'self' https:", $csp, $mode);
        }
    }

    // --- HSTS --------------------------------------------------------------

    public function testHstsIsOmittedOnAPlainHttpRequest(): void
    {
        $headers = $this->headers(['security.hsts_max_age' => 31536000], secure: false);

        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testHstsIsEmittedOnlyOverHttps(): void
    {
        $headers = $this->headers(['security.hsts_max_age' => 31536000], secure: true);

        self::assertSame('max-age=31536000', $headers['Strict-Transport-Security']);
    }

    public function testHstsIsOmittedWhenTheMaxAgeIsZeroOrNegative(): void
    {
        self::assertArrayNotHasKey('Strict-Transport-Security', $this->headers(['security.hsts_max_age' => 0], secure: true));
        self::assertArrayNotHasKey('Strict-Transport-Security', $this->headers(['security.hsts_max_age' => -1], secure: true));
        self::assertArrayNotHasKey('Strict-Transport-Security', $this->headers([], secure: true));
    }

    public function testHstsDirectivesAreAppendedOnlyWhenEnabled(): void
    {
        $headers = $this->headers([
            'security.hsts_max_age' => 63072000,
            'security.hsts_subdomains' => true,
            'security.hsts_preload' => true,
        ], secure: true);

        self::assertSame('max-age=63072000; includeSubDomains; preload', $headers['Strict-Transport-Security']);
    }

    public function testUpgradeInsecureRequestsFollowsHsts(): void
    {
        $withHsts = $this->headers(['security.csp_mode' => 'strict', 'security.hsts_max_age' => 31536000], secure: true);
        $withoutHsts = $this->headers(['security.csp_mode' => 'strict'], secure: true);

        self::assertStringContainsString('upgrade-insecure-requests', $withHsts['Content-Security-Policy']);
        self::assertStringNotContainsString('upgrade-insecure-requests', $withoutHsts['Content-Security-Policy']);
    }

    // --- framing and referrer ---------------------------------------------

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function frameAncestorSettings(): iterable
    {
        yield 'default is self' => ['', 'SAMEORIGIN'];
        yield 'explicit self' => ["'self'", 'SAMEORIGIN'];
        yield 'none' => ["'none'", 'DENY'];
        yield 'custom list gets no X-Frame-Options' => ['https://partner.example', null];
    }

    #[DataProvider('frameAncestorSettings')]
    public function testFrameOptionsMirrorsFrameAncestorsOnlyWhenItCan(string $ancestors, ?string $expected): void
    {
        $headers = $this->headers(['security.csp_frame_ancestors' => $ancestors]);

        if ($expected === null) {
            self::assertArrayNotHasKey('X-Frame-Options', $headers);
        } else {
            self::assertSame($expected, $headers['X-Frame-Options']);
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function referrerPolicies(): iterable
    {
        yield 'no-referrer' => ['no-referrer', 'no-referrer'];
        yield 'same-origin' => ['same-origin', 'same-origin'];
        yield 'strict-origin' => ['strict-origin', 'strict-origin'];
        yield 'unset falls back' => [null, 'strict-origin-when-cross-origin'];
        yield 'unknown value falls back' => ['unsafe-url', 'strict-origin-when-cross-origin'];
        yield 'empty falls back' => ['', 'strict-origin-when-cross-origin'];
    }

    #[DataProvider('referrerPolicies')]
    public function testReferrerPolicyIsAllowlisted(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->headers(['security.referrer_policy' => $stored])['Referrer-Policy']);
    }

    // --- operator-typed source lists --------------------------------------

    /**
     * A ';' in an operator-typed source list would end the directive and start a
     * new one, letting a settings value rewrite the whole policy.
     */
    public function testSemicolonsInOperatorSourceListsCannotInjectADirective(): void
    {
        $headers = $this->headers([
            'security.csp_mode' => 'strict',
            'security.csp_script_src' => "https://cdn.example; default-src *; object-src *",
        ]);
        $csp = $headers['Content-Security-Policy'];

        // The legitimate host survives; the smuggled directive names do not, and
        // neither directive they tried to redefine changed.
        self::assertStringContainsString('https://cdn.example', $csp);
        self::assertStringStartsWith("default-src 'self';", $csp);
        self::assertStringContainsString("; object-src 'none';", $csp);
        self::assertSame(1, substr_count($csp, 'default-src'));
        self::assertSame(1, substr_count($csp, 'object-src'));

        // Regression: the leftover directive names used to land inside script-src
        // as loose source tokens. A bare '*' is still a source expression the
        // operator typed and is left where they typed it, but it must not have
        // leaked into any other directive.
        self::assertStringNotContainsString('default-src', substr($csp, \strlen("default-src 'self';")));
        self::assertStringNotContainsString('object-src', str_replace("object-src 'none'", '', $csp));
        self::assertStringContainsString("default-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self';", $csp);
    }

    public function testNewlinesAndCommasInSourceListsBecomeSeparateTokens(): void
    {
        $csp = $this->headers([
            'security.csp_mode' => 'strict',
            'security.csp_img_src' => "https://a.example,\nhttps://b.example  https://c.example",
        ])['Content-Security-Policy'];

        self::assertStringContainsString('https://a.example', $csp);
        self::assertStringContainsString('https://b.example', $csp);
        self::assertStringContainsString('https://c.example', $csp);
        self::assertStringNotContainsString("\n", $csp);
    }

    public function testPermissionsPolicyIsOnlyEmittedWhenConfigured(): void
    {
        self::assertArrayNotHasKey('Permissions-Policy', $this->headers([]));
        self::assertArrayNotHasKey('Permissions-Policy', $this->headers(['security.permissions_policy' => '   ']));
        self::assertSame(
            'geolocation=(), camera=()',
            $this->headers(['security.permissions_policy' => ' geolocation=(), camera=() '])['Permissions-Policy'],
        );
    }

    // --- nonce provider ----------------------------------------------------

    public function testNonceIsStableWithinARequestAndDiffersBetweenRequests(): void
    {
        $stack = new RequestStack();
        $provider = new CspNonceProvider($stack);

        $first = Request::create('/tr');
        $second = Request::create('/tr');

        $a = $provider->nonceFor($first);

        self::assertSame($a, $provider->nonceFor($first), 'The same request must reuse its nonce.');
        self::assertNotSame($a, $provider->nonceFor($second), 'A different request must get a different nonce.');

        // 16 random bytes, base64url without padding.
        self::assertSame(22, \strlen($a));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $a);
    }

    public function testNonceIsEmptyWithoutARequestSoTemplatesStayRenderable(): void
    {
        self::assertSame('', (new CspNonceProvider(new RequestStack()))->nonce());
    }

    // --- subscriber --------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $existing
     */
    private function respond(array $settings, string $path = '/tr', array $existing = []): Response
    {
        $policy = $this->policy($settings);
        $stack = new RequestStack();
        $provider = new CspNonceProvider($stack);
        $subscriber = new SecurityHeadersSubscriber($policy, $provider);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);
        $response = new Response('<html></html>', 200, $existing);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    public function testSubscriberMintsTheNonceOnRequestSoTheResponseCanUseIt(): void
    {
        $response = $this->respond(['security.csp_mode' => 'strict']);

        self::assertMatchesRegularExpression(
            "/'nonce-[A-Za-z0-9_-]{22}'/",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testAnExistingHeaderIsNeverOverwritten(): void
    {
        $response = $this->respond(
            ['security.csp_mode' => 'strict'],
            existing: [
                'Content-Security-Policy' => "default-src 'none'",
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
            ],
        );

        self::assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        // Headers the controller did not set are still applied.
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testHeadersAreSkippedEntirelyWhenDisabled(): void
    {
        $response = $this->respond(['security.headers_enabled' => false, 'security.csp_mode' => 'strict']);

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function profilerPaths(): iterable
    {
        yield 'web debug toolbar' => ['/_wdt/abc123'];
        yield 'profiler' => ['/_profiler/abc123'];
    }

    #[DataProvider('profilerPaths')]
    public function testProfilerResponsesAreLeftAlone(string $path): void
    {
        $response = $this->respond(['security.csp_mode' => 'strict'], $path);

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Content-Type-Options'));
    }

    /**
     * Regression: a download used to be skipped wholesale, so an attachment whose
     * bytes a user supplied reached the browser with nothing stopping it from
     * being re-typed as HTML and executed on our origin.
     */
    public function testDownloadsStillGetTheAntiSniffingHeadersButNoCsp(): void
    {
        $response = $this->respond(
            ['security.csp_mode' => 'strict'],
            existing: ['Content-Disposition' => 'attachment; filename="export.csv"'],
        );

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testInlineDispositionIsNotTreatedAsADownload(): void
    {
        $response = $this->respond(
            ['security.csp_mode' => 'strict'],
            existing: ['Content-Disposition' => 'inline; filename="preview.pdf"'],
        );

        self::assertTrue($response->headers->has('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testBalancedModeMintsNoNonceOnTheRequest(): void
    {
        $policy = $this->policy(['security.csp_mode' => 'balanced']);
        $stack = new RequestStack();
        $subscriber = new SecurityHeadersSubscriber($policy, new CspNonceProvider($stack));

        $request = Request::create('/tr');
        $subscriber->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertNull($request->attributes->get(CspNonceProvider::ATTRIBUTE));
    }
}
