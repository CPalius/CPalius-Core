<?php

declare(strict_types=1);

namespace App\Core\Storage;

/**
 * "Which target should this part of the system write to right now?"
 *
 * The consumers — media offload and backup shipping — need exactly this and
 * nothing else. StorageTargetRegistry also saves credentials, runs write probes
 * and records verdicts, none of which is any of their business: a class that
 * uploads a file should not be one refactor away from being able to rewrite the
 * secret key.
 *
 * It is also what makes those two testable without a network. The registry
 * builds its targets from settings by design, so a test that wanted a fake
 * bucket would otherwise have to either reach a real one or reach inside the
 * registry.
 */
interface TargetResolverInterface
{
    /**
     * The target a purpose should use, or null for "keep it local".
     *
     * Implementations MUST return null for a target that has not passed a live
     * write probe. That is the fail-closed half of the design: an operator who
     * picks a destination and mistypes the bucket gets local-only behaviour and
     * a red badge, not uploads quietly disappearing into a 403.
     *
     * @param string $purpose StorageTargetRegistry::PURPOSE_MEDIA|PURPOSE_BACKUP
     */
    public function resolveFor(string $purpose): ?RemoteTargetInterface;

    /**
     * What the operator selected, whether or not it is usable — the screens
     * have to be able to tell "off" apart from "configured but not verified".
     */
    public function selectedType(string $purpose): string;
}
