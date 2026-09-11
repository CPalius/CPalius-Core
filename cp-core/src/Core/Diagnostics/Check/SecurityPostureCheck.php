<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Security\Audit\SecurityAuditor;
use App\Core\Security\Audit\SecurityFinding;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Surfaces the security posture score inside cp:doctor.
 *
 * This is a bridge, not a second auditor: cp:security:audit stays the detailed
 * report, and this check exists so that an operator running a single command
 * cannot miss a critical security finding just because they ran the general
 * health tool instead of the security one.
 *
 * Only critical and high findings are promoted individually — anything milder
 * is summarised, because a doctor report that reprints eighteen security rows
 * buries its own other findings.
 */
final class SecurityPostureCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly SecurityAuditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'security';
    }

    public function run(): array
    {
        $findings = $this->auditor->run();
        $score = $this->auditor->score($findings);

        $promoted = [];
        $milder = 0;

        foreach ($findings as $finding) {
            if ($finding->isPass()) {
                continue;
            }

            if (\in_array($finding->severity, [SecurityFinding::SEVERITY_CRITICAL, SecurityFinding::SEVERITY_HIGH], true)) {
                $promoted[] = new DoctorFinding(
                    id: 'security.'.$finding->id,
                    severity: $finding->severity,
                    title: $this->translate($finding->titleKey),
                    detail: $this->translate($finding->detailKey, $finding->parameters),
                    remedy: 'php cp-core/bin/console cp:security:audit',
                );

                continue;
            }

            ++$milder;
        }

        $promoted[] = new DoctorFinding(
            id: 'security.posture',
            severity: $this->severityForScore($score, $milder),
            title: 'Security posture',
            detail: sprintf('%d/100, %d finding(s) below high severity.', $score, $milder),
            remedy: $milder > 0 ? 'php cp-core/bin/console cp:security:audit' : null,
        );

        return $promoted;
    }

    private function severityForScore(int $score, int $milder): string
    {
        if ($score < 50) {
            return DoctorFinding::SEVERITY_HIGH;
        }

        if ($score < 80) {
            return DoctorFinding::SEVERITY_MEDIUM;
        }

        return $milder > 0 ? DoctorFinding::SEVERITY_LOW : DoctorFinding::SEVERITY_PASS;
    }

    /**
     * The security auditor speaks in translation keys so the same finding can
     * render in AACP; cp:doctor resolves them here. A missing key falls back
     * to the key itself rather than to an empty line.
     *
     * @param array<string, mixed> $parameters
     */
    private function translate(string $key, array $parameters = []): string
    {
        try {
            $translated = $this->translator->trans($key, $parameters);
        } catch (\Throwable) {
            return $key;
        }

        return $translated === '' ? $key : $translated;
    }
}
