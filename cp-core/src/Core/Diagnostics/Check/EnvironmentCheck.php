<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Inspects the runtime environment: writable paths, PHP extensions, and the
 * two recovery doors that are useless when left unset.
 *
 * Everything here is deliberately cheap and dependency-free, so this check
 * still reports something useful on an installation where the database is
 * unreachable and most other checks can only say "unavailable".
 */
final class EnvironmentCheck implements DoctorCheckInterface
{
    /** Extensions the core genuinely needs; absence is a hard failure, not a warning. */
    private const REQUIRED_EXTENSIONS = ['ctype', 'fileinfo', 'iconv', 'json', 'pdo', 'openssl'];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    public function key(): string
    {
        return 'environment';
    }

    public function run(): array
    {
        return [
            ...$this->checkDebug(),
            ...$this->checkExtensions(),
            ...$this->checkWritablePaths(),
            ...$this->checkRecoveryDoors(),
        ];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkDebug(): array
    {
        if ($this->environment === 'prod' && $this->debug) {
            return [new DoctorFinding(
                id: 'environment.debug_in_prod',
                severity: DoctorFinding::SEVERITY_CRITICAL,
                title: 'Debug mode is on in production',
                detail: 'APP_ENV=prod with APP_DEBUG enabled exposes stack traces, service names and configuration to visitors.',
                remedy: 'Set APP_DEBUG=0 in .env.local and clear the cache.',
            )];
        }

        return [DoctorFinding::pass(
            'environment.debug_in_prod',
            'Debug mode',
            sprintf('APP_ENV=%s, debug %s.', $this->environment, $this->debug ? 'on' : 'off'),
        )];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkExtensions(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => !\extension_loaded($extension),
        ));

        if ($missing === []) {
            return [DoctorFinding::pass(
                'environment.extensions',
                'PHP extensions',
                sprintf('All %d required extensions are loaded (PHP %s).', \count(self::REQUIRED_EXTENSIONS), \PHP_VERSION),
            )];
        }

        return [new DoctorFinding(
            id: 'environment.extensions',
            severity: DoctorFinding::SEVERITY_CRITICAL,
            title: 'Required PHP extensions are missing',
            detail: implode(', ', $missing),
            remedy: 'Enable them in php.ini; the core cannot function without them.',
        )];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkWritablePaths(): array
    {
        $paths = [
            'cp-core/var' => true,
            'cp-core/var/cache' => false,
            'cp-core/var/log' => false,
            'public/uploads' => false,
        ];

        $findings = [];
        $ok = 0;

        foreach ($paths as $relative => $required) {
            $absolute = $this->projectDir.'/'.$relative;

            if (!is_dir($absolute)) {
                if ($required) {
                    $findings[] = new DoctorFinding(
                        id: 'environment.path_missing',
                        severity: DoctorFinding::SEVERITY_HIGH,
                        title: 'Required directory is missing',
                        detail: $relative,
                        remedy: sprintf('mkdir -p %s', $relative),
                    );
                }

                // An optional directory that does not exist yet is created on
                // demand by the component that owns it; not a finding.
                continue;
            }

            if (!is_writable($absolute)) {
                $findings[] = new DoctorFinding(
                    id: 'environment.path_not_writable',
                    severity: DoctorFinding::SEVERITY_HIGH,
                    title: 'Directory is not writable',
                    detail: $relative,
                    remedy: 'Grant write permission to the web server and CLI user.',
                );

                continue;
            }

            ++$ok;
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::pass(
                'environment.path_not_writable',
                'Writable paths',
                sprintf('%d path(s) present and writable.', $ok),
            );
        }

        return $findings;
    }

    /**
     * The recovery console and the pseudo-cron endpoint both fail safe: an
     * unset token closes the door entirely. That is the correct default, but
     * an operator who believes the door exists and has never set the token
     * will discover it at the worst possible moment.
     *
     * @return list<DoctorFinding>
     */
    private function checkRecoveryDoors(): array
    {
        $findings = [];

        foreach ([
            'AACP_RECOVERY_TOKEN' => 'The /aacp/recovery console is disabled; there is no way back in if the database or firewall fails.',
            'CRON_TOKEN' => 'The /cron/execute endpoint is disabled; scheduled jobs only run if a real crontab entry exists.',
        ] as $variable => $consequence) {
            $value = $_ENV[$variable] ?? $_SERVER[$variable] ?? '';

            if (!\is_string($value) || trim($value) === '') {
                $findings[] = new DoctorFinding(
                    id: 'environment.closed_door',
                    severity: DoctorFinding::SEVERITY_LOW,
                    title: sprintf('%s is not set', $variable),
                    detail: $consequence,
                    remedy: sprintf('Set %s in .env.local to a random value: php -r "echo bin2hex(random_bytes(32));"', $variable),
                );

                continue;
            }

            // A token short enough to guess is worse than no token at all: the
            // door is open and the operator believes it is guarded.
            if (\strlen(trim($value)) < 32) {
                $findings[] = new DoctorFinding(
                    id: 'environment.weak_door',
                    severity: DoctorFinding::SEVERITY_HIGH,
                    title: sprintf('%s is too short', $variable),
                    detail: sprintf('%d characters; this endpoint is reachable by anyone who can guess it.', \strlen(trim($value))),
                    remedy: 'Use at least 32 random characters.',
                );
            }
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::pass(
                'environment.closed_door',
                'Recovery doors',
                'Recovery console and cron endpoint tokens are set.',
            );
        }

        return $findings;
    }
}
