<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Extension\ExtensionCatalogue;

/**
 * Installed modules and themes that the signed extension index lists a security advisory for.
 * Reads the stored copy of the index only, so it never waits on the network.
 */
final class ExtensionAdvisoryCheck implements DoctorCheckInterface
{
    public function __construct(private readonly ExtensionCatalogue $catalogue)
    {
    }

    public function key(): string
    {
        return 'advisories';
    }

    public function run(): array
    {
        try {
            $advisories = $this->catalogue->advisories();
        } catch (\Throwable $e) {
            return [new DoctorFinding('advisories.unreadable', DoctorFinding::SEVERITY_LOW, 'Extension advisories', 'The stored extension index could not be read: '.$e->getMessage())];
        }

        if ($advisories === []) {
            return [DoctorFinding::pass('advisories.none', 'Extension advisories', 'No installed module or theme has a published security advisory.')];
        }

        $findings = [];
        foreach ($advisories as $a) {
            $findings[] = new DoctorFinding(
                'advisories.'.$a['type'].'.'.$a['name'].'.'.$a['id'],
                $a['severity'],
                sprintf('%s %s: %s', $a['title'], $a['installed'], $a['id']),
                $a['summary'] !== '' ? $a['summary'] : 'A security advisory applies to the installed version.',
                $a['fixed'] !== '' ? 'Update to '.$a['fixed'].' or later (AACP → Store).' : 'Update from AACP → Store, or disable the extension.',
            );
        }

        return $findings;
    }
}
