<?php

declare(strict_types=1);

namespace App\Core\Security\Audit;

use App\Core\Security\EventListener\RequestGuardSubscriber;
use App\Core\Security\Http\SecurityHeaderPolicy;
use App\Core\Security\Password\PasswordPolicy;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Settings\SettingsRegistry;
use App\Repository\UserRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Self-audit of the running configuration. Flags combinations that cancel each other out.
 */
final class SecurityAuditor
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SecurityHeaderPolicy $headerPolicy,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TwoFactorService $twoFactor,
        private readonly IpBanService $ipBanService,
        private readonly RequestStack $requestStack,
        private readonly UserRepository $userRepository,
        private readonly Connection $connection,
        private readonly bool $debug,
        private readonly string $environment,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<SecurityFinding>
     */
    public function run(): array
    {
        return array_merge(
            $this->auditEnvironment(),
            $this->auditHeaders(),
            $this->auditPerimeter(),
            $this->auditCredentials(),
            $this->auditData(),
        );
    }

    /**
     * @param list<SecurityFinding> $findings
     */
    public function score(array $findings): int
    {
        $score = 100;

        foreach ($findings as $finding) {
            $score -= $finding->penalty();
        }

        return max(0, min(100, $score));
    }

    /**
     * @param list<SecurityFinding> $findings
     *
     * @return array<string, int>
     */
    public function summary(array $findings): array
    {
        $summary = [
            SecurityFinding::SEVERITY_CRITICAL => 0,
            SecurityFinding::SEVERITY_HIGH => 0,
            SecurityFinding::SEVERITY_MEDIUM => 0,
            SecurityFinding::SEVERITY_LOW => 0,
            SecurityFinding::SEVERITY_PASS => 0,
        ];

        foreach ($findings as $finding) {
            $summary[$finding->severity] = ($summary[$finding->severity] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * public/ must contain only index.php. Other PHP files are served before Symfony (no WAF/ban/telemetry).
     */
    private function auditDocumentRootScripts(): SecurityFinding
    {
        $publicDir = $this->projectDir.'/public';
        $stray = [];

        foreach (glob($publicDir.'/*.php') ?: [] as $file) {
            if (basename($file) !== 'index.php') {
                $stray[] = basename($file);
            }
        }

        if ($stray === []) {
            return SecurityFinding::pass(
                'env.docroot_scripts',
                'aacp.security.audit.docroot_scripts',
                'aacp.security.audit.docroot_scripts_clean',
            );
        }

        sort($stray);

        return new SecurityFinding(
            'env.docroot_scripts',
            SecurityFinding::SEVERITY_CRITICAL,
            'aacp.security.audit.docroot_scripts',
            'aacp.security.audit.docroot_scripts_found',
            ['files' => implode(', ', $stray)],
        );
    }

    /**
     * @return list<SecurityFinding>
     */
    private function auditEnvironment(): array
    {
        $findings = [];

        if ($this->environment === 'prod' && $this->debug) {
            $findings[] = new SecurityFinding(
                'env.debug',
                SecurityFinding::SEVERITY_CRITICAL,
                'aacp.security.audit.debug',
                'aacp.security.audit.debug_on',
            );
        } else {
            $findings[] = SecurityFinding::pass(
                'env.debug',
                'aacp.security.audit.debug',
                'aacp.security.audit.debug_off',
            );
        }

        $findings[] = $this->auditDocumentRootScripts();

        $request = $this->requestStack->getMainRequest();

        // Both checks below read the live request. Run from the console there is
        // none, and reporting a pass we cannot substantiate would be worse than
        // reporting nothing, so they are skipped instead.
        if ($request instanceof Request) {
            if (!$request->isSecure()) {
                $findings[] = new SecurityFinding(
                    'env.https',
                    $this->environment === 'prod' ? SecurityFinding::SEVERITY_HIGH : SecurityFinding::SEVERITY_LOW,
                    'aacp.security.audit.https',
                    'aacp.security.audit.https_off',
                );
            } else {
                $findings[] = SecurityFinding::pass(
                    'env.https',
                    'aacp.security.audit.https',
                    'aacp.security.audit.https_on',
                );
            }

            // A forwarded-for header that arrives while no proxy is trusted means the
            // client IP the whole hardening layer keys on is the proxy's, not the
            // visitor's — bans and rate limits then hit everyone or no one.
            $trustedProxies = Request::getTrustedProxies();
            $hasForwardedHeader = $request->headers->has('X-Forwarded-For');

            if ($hasForwardedHeader && $trustedProxies === []) {
                $findings[] = new SecurityFinding(
                    'env.trusted_proxies',
                    SecurityFinding::SEVERITY_HIGH,
                    'aacp.security.audit.trusted_proxies',
                    'aacp.security.audit.trusted_proxies_missing',
                );
            } elseif (!$hasForwardedHeader && $trustedProxies !== []) {
                $findings[] = new SecurityFinding(
                    'env.trusted_proxies',
                    SecurityFinding::SEVERITY_MEDIUM,
                    'aacp.security.audit.trusted_proxies',
                    'aacp.security.audit.trusted_proxies_unused',
                );
            } else {
                $findings[] = SecurityFinding::pass(
                    'env.trusted_proxies',
                    'aacp.security.audit.trusted_proxies',
                    'aacp.security.audit.trusted_proxies_ok',
                );
            }
        }

        $trustedHosts = trim((string) ($this->settings->get('security.trusted_hosts') ?? ''));
        $findings[] = $trustedHosts === ''
            ? new SecurityFinding(
                'env.trusted_hosts',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.trusted_hosts',
                'aacp.security.audit.trusted_hosts_empty',
            )
            : SecurityFinding::pass(
                'env.trusted_hosts',
                'aacp.security.audit.trusted_hosts',
                'aacp.security.audit.trusted_hosts_ok',
            );

        return $findings;
    }

    /**
     * @return list<SecurityFinding>
     */
    private function auditHeaders(): array
    {
        $findings = [];

        if (!$this->headerPolicy->enabled()) {
            return [new SecurityFinding(
                'headers.enabled',
                SecurityFinding::SEVERITY_CRITICAL,
                'aacp.security.audit.headers',
                'aacp.security.audit.headers_off',
            )];
        }

        $findings[] = SecurityFinding::pass(
            'headers.enabled',
            'aacp.security.audit.headers',
            'aacp.security.audit.headers_on',
        );

        $mode = $this->headerPolicy->cspMode();
        $findings[] = match ($mode) {
            SecurityHeaderPolicy::MODE_OFF => new SecurityFinding(
                'headers.csp',
                SecurityFinding::SEVERITY_HIGH,
                'aacp.security.audit.csp',
                'aacp.security.audit.csp_off',
            ),
            SecurityHeaderPolicy::MODE_REPORT => new SecurityFinding(
                'headers.csp',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.csp',
                'aacp.security.audit.csp_report',
            ),
            SecurityHeaderPolicy::MODE_BALANCED => new SecurityFinding(
                'headers.csp',
                SecurityFinding::SEVERITY_LOW,
                'aacp.security.audit.csp',
                'aacp.security.audit.csp_balanced',
            ),
            default => SecurityFinding::pass(
                'headers.csp',
                'aacp.security.audit.csp',
                'aacp.security.audit.csp_strict',
            ),
        };

        $maxAge = $this->headerPolicy->hstsMaxAge();
        if ($maxAge <= 0) {
            $findings[] = new SecurityFinding(
                'headers.hsts',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.hsts',
                'aacp.security.audit.hsts_off',
            );
        } elseif ($maxAge < 15552000) {
            $findings[] = new SecurityFinding(
                'headers.hsts',
                SecurityFinding::SEVERITY_LOW,
                'aacp.security.audit.hsts',
                'aacp.security.audit.hsts_short',
                ['maxAge' => $maxAge],
            );
        } else {
            $findings[] = SecurityFinding::pass(
                'headers.hsts',
                'aacp.security.audit.hsts',
                'aacp.security.audit.hsts_ok',
                ['maxAge' => $maxAge],
            );
        }

        return $findings;
    }

    /**
     * @return list<SecurityFinding>
     */
    private function auditPerimeter(): array
    {
        $findings = [];

        // Normalised exactly the way RequestGuardSubscriber normalises it. Reading
        // the raw setting meant a typo ("blockk") fell into the match default and
        // was reported as a PASS for enforcing mode, while the guard was actually
        // running in detect — the auditor's whole reason to exist is to not report
        // a posture it cannot substantiate.
        $rawWafMode = (string) ($this->settings->get('security.waf_mode') ?? RequestGuardSubscriber::MODE_DETECT);
        $wafMode = \in_array($rawWafMode, [
            RequestGuardSubscriber::MODE_OFF,
            RequestGuardSubscriber::MODE_DETECT,
            RequestGuardSubscriber::MODE_BLOCK,
        ], true) ? $rawWafMode : RequestGuardSubscriber::MODE_DETECT;

        $findings[] = match ($wafMode) {
            'off' => new SecurityFinding(
                'waf.mode',
                SecurityFinding::SEVERITY_HIGH,
                'aacp.security.audit.waf',
                'aacp.security.audit.waf_off',
            ),
            'detect' => new SecurityFinding(
                'waf.mode',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.waf',
                'aacp.security.audit.waf_detect',
            ),
            default => SecurityFinding::pass(
                'waf.mode',
                'aacp.security.audit.waf',
                'aacp.security.audit.waf_block',
            ),
        };

        $findings[] = (bool) $this->settings->get('security.flood_enabled', true)
            ? SecurityFinding::pass('flood.enabled', 'aacp.security.audit.flood', 'aacp.security.audit.flood_on')
            : new SecurityFinding(
                'flood.enabled',
                SecurityFinding::SEVERITY_CRITICAL,
                'aacp.security.audit.flood',
                'aacp.security.audit.flood_off',
            );

        // Locking accounts without ever banning the source means an attacker can
        // keep every account in the site locked indefinitely at no cost.
        $autoban = (int) $this->settings->get('security.autoban_after_lockouts', 3);
        $findings[] = $autoban > 0
            ? SecurityFinding::pass('flood.autoban', 'aacp.security.audit.autoban', 'aacp.security.audit.autoban_on', ['count' => $autoban])
            : new SecurityFinding(
                'flood.autoban',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.autoban',
                'aacp.security.audit.autoban_off',
            );

        $findings[] = $this->ipBanService->allowlist() === []
            ? new SecurityFinding(
                'waf.allowlist',
                SecurityFinding::SEVERITY_LOW,
                'aacp.security.audit.allowlist',
                'aacp.security.audit.allowlist_empty',
            )
            : SecurityFinding::pass('waf.allowlist', 'aacp.security.audit.allowlist', 'aacp.security.audit.allowlist_ok');

        $findings[] = (bool) $this->settings->get('telemetry.security_enabled', false)
            ? SecurityFinding::pass('telemetry.security', 'aacp.security.audit.telemetry', 'aacp.security.audit.telemetry_on')
            : new SecurityFinding(
                'telemetry.security',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.telemetry',
                'aacp.security.audit.telemetry_off',
            );

        return $findings;
    }

    /**
     * @return list<SecurityFinding>
     */
    private function auditCredentials(): array
    {
        $findings = [];

        $minLength = $this->passwordPolicy->minLength();
        $findings[] = $minLength >= 10
            ? SecurityFinding::pass('password.length', 'aacp.security.audit.password_length', 'aacp.security.audit.password_length_ok', ['min' => $minLength])
            : new SecurityFinding(
                'password.length',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.password_length',
                'aacp.security.audit.password_length_short',
                ['min' => $minLength],
            );

        $findings[] = (bool) $this->settings->get('security.password_breach_check', true)
            ? SecurityFinding::pass('password.breach', 'aacp.security.audit.breach', 'aacp.security.audit.breach_on')
            : new SecurityFinding(
                'password.breach',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.breach',
                'aacp.security.audit.breach_off',
            );

        if (!$this->twoFactor->isEnabledGlobally()) {
            $findings[] = new SecurityFinding(
                'twofactor.enabled',
                SecurityFinding::SEVERITY_HIGH,
                'aacp.security.audit.twofactor',
                'aacp.security.audit.twofactor_off',
            );
        } elseif (!(bool) $this->settings->get('security.twofactor_enforce_privileged', false)) {
            $findings[] = new SecurityFinding(
                'twofactor.enabled',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.twofactor',
                'aacp.security.audit.twofactor_optional',
            );
        } else {
            $findings[] = SecurityFinding::pass(
                'twofactor.enabled',
                'aacp.security.audit.twofactor',
                'aacp.security.audit.twofactor_enforced',
            );
        }

        $idle = (int) $this->settings->get('security.session_idle_minutes', 0);
        $findings[] = $idle > 0
            ? SecurityFinding::pass('session.idle', 'aacp.security.audit.session_idle', 'aacp.security.audit.session_idle_ok', ['minutes' => $idle])
            : new SecurityFinding(
                'session.idle',
                SecurityFinding::SEVERITY_LOW,
                'aacp.security.audit.session_idle',
                'aacp.security.audit.session_idle_off',
            );

        return $findings;
    }

    /**
     * @return list<SecurityFinding>
     */
    private function auditData(): array
    {
        $findings = [];

        $plaintextSecrets = $this->countPlaintextSecrets();
        $findings[] = $plaintextSecrets > 0
            ? new SecurityFinding(
                'settings.secrets',
                SecurityFinding::SEVERITY_MEDIUM,
                'aacp.security.audit.secrets',
                'aacp.security.audit.secrets_plaintext',
                ['count' => $plaintextSecrets],
            )
            : SecurityFinding::pass('settings.secrets', 'aacp.security.audit.secrets', 'aacp.security.audit.secrets_sealed');

        $adminCount = $this->countPrivilegedAccounts();
        if ($adminCount === 0) {
            $findings[] = new SecurityFinding(
                'users.admins',
                SecurityFinding::SEVERITY_LOW,
                'aacp.security.audit.admins',
                'aacp.security.audit.admins_none',
            );
        } elseif ($adminCount > 5) {
            $findings[] = new SecurityFinding(
                'users.admins',
                SecurityFinding::SEVERITY_LOW,
                'aacp.security.audit.admins',
                'aacp.security.audit.admins_many',
                ['count' => $adminCount],
            );
        } else {
            $findings[] = SecurityFinding::pass(
                'users.admins',
                'aacp.security.audit.admins',
                'aacp.security.audit.admins_ok',
                ['count' => $adminCount],
            );
        }

        return $findings;
    }

    /**
     * Counts password-type settings still stored without the encryption marker.
     * Read straight from the table: the registry deliberately hides the stored form.
     */
    private function countPlaintextSecrets(): int
    {
        $keys = [];
        foreach ($this->settings->all() as $definition) {
            if ($definition->type === 'password') {
                $keys[] = $definition->key;
            }
        }

        if ($keys === []) {
            return 0;
        }

        try {
            /** @var list<string> $values */
            $values = $this->connection->fetchFirstColumn(
                'SELECT setting_value FROM cp_settings WHERE setting_key IN (:keys)',
                ['keys' => $keys],
                ['keys' => ArrayParameterType::STRING],
            );
        } catch (DBALException) {
            return 0;
        }

        $plaintext = 0;
        foreach ($values as $value) {
            if (trim((string) $value) !== '' && !str_starts_with((string) $value, 'cpenc:v1:')) {
                ++$plaintext;
            }
        }

        return $plaintext;
    }

    private function countPrivilegedAccounts(): int
    {
        try {
            return $this->userRepository->count([]) > 0
                ? (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM cp_users WHERE JSON_SEARCH(roles, 'one', 'admin') IS NOT NULL",
                )
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
