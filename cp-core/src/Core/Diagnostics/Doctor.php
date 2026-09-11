<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Runs every registered health check and collects the findings.
 *
 * The isolation rule of the core applies here too, and more sharply than
 * usual: cp:doctor is the tool an operator reaches for when the installation
 * is already broken. If a check throws — very likely, because checks touch
 * exactly the subsystems that are failing — the run continues and the
 * exception becomes a finding of its own. A diagnostic that dies on the first
 * real problem would be useless precisely when it is needed.
 */
final class Doctor
{
    /**
     * @param iterable<DoctorCheckInterface> $checks
     */
    public function __construct(
        #[TaggedIterator('cpalius.doctor.check')]
        private readonly iterable $checks,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<string> $only restrict the run to these check keys; empty means all
     *
     * @return list<DoctorFinding>
     */
    public function run(array $only = []): array
    {
        $findings = [];

        foreach ($this->checks as $check) {
            try {
                $key = $check->key();
            } catch (\Throwable $e) {
                $findings[] = $this->crashed($check::class, $e);
                continue;
            }

            if ($only !== [] && !\in_array($key, $only, true)) {
                continue;
            }

            try {
                foreach ($check->run() as $finding) {
                    $findings[] = $finding;
                }
            } catch (\Throwable $e) {
                $findings[] = $this->crashed($check::class, $e);
            }
        }

        usort(
            $findings,
            static fn (DoctorFinding $a, DoctorFinding $b) => [$a->rank(), $a->id] <=> [$b->rank(), $b->id],
        );

        return $findings;
    }

    /**
     * @return list<string>
     */
    public function availableKeys(): array
    {
        $keys = [];

        foreach ($this->checks as $check) {
            try {
                $keys[] = $check->key();
            } catch (\Throwable) {
                // A check that cannot even name itself is reported by run(),
                // not here; listing keys must never fail.
                continue;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * Counts per severity, always including every severity so a caller can
     * render a stable table without special-casing zeros.
     *
     * @param list<DoctorFinding> $findings
     *
     * @return array<string, int>
     */
    public function summary(array $findings): array
    {
        $summary = array_fill_keys(DoctorFinding::ORDER, 0);

        foreach ($findings as $finding) {
            if (!isset($summary[$finding->severity])) {
                $summary[$finding->severity] = 0;
            }

            ++$summary[$finding->severity];
        }

        return $summary;
    }

    /**
     * True when nothing needs the operator's attention.
     *
     * @param list<DoctorFinding> $findings
     */
    public function isHealthy(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (!$finding->isPass()) {
                return false;
            }
        }

        return true;
    }

    private function crashed(string $checkClass, \Throwable $e): DoctorFinding
    {
        $this->logger?->error('Doctor check failed.', ['check' => $checkClass, 'exception' => $e]);

        return new DoctorFinding(
            id: 'doctor.check_failed',
            severity: DoctorFinding::SEVERITY_MEDIUM,
            title: 'A health check could not complete',
            detail: sprintf('%s threw %s: %s', $checkClass, $e::class, $e->getMessage()),
            remedy: 'This is a defect in the check itself, or the subsystem it inspects is unavailable.',
        );
    }
}
