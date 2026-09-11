<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One diagnostic performed by cp:doctor.
 *
 * Implementations are collected by tag, so a module can contribute its own
 * check without the core knowing that the module exists (Law: core never
 * imports Modules\*).
 *
 * A check MUST NOT modify anything. cp:doctor is run on installations that are
 * already misbehaving; a diagnostic that writes is a diagnostic that can make
 * a bad situation worse.
 */
#[AutoconfigureTag('cpalius.doctor.check')]
interface DoctorCheckInterface
{
    /**
     * Stable, kebab-case group key (for example "migrations", "modules").
     * Used by --only to run a subset.
     */
    public function key(): string;

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array;
}
