<?php

declare(strict_types=1);

namespace App\Core\Version;

/**
 * The running core version — the single source of truth.
 *
 * Why a constant and not CP_APP_VERSION in .env:
 * .env is DEPLOYMENT configuration. It is written once at install time and is
 * deliberately never overwritten by an update, because it holds the database
 * password and the recovery token. A version number living there therefore
 * describes the version the site was INSTALLED at, not the version it is
 * RUNNING — and after the first update those two diverge permanently.
 *
 * That divergence is invisible until it matters, and then it matters a lot:
 * UpdateRunner decides which hooks still have to run by comparing hook
 * versions against the running version. Read the version from .env and a site
 * updated from 1.0.0 to 1.2.0 still reports 1.0.0, so every 1.1.x and 1.2.x
 * hook is offered again on every single cp:update run.
 *
 * A constant travels with the code. Overwrite the files, and the version is
 * correct by construction — there is no second place to remember to edit.
 *
 * Format: MAJOR.MINOR.PATCH with an optional fourth HOTFIX segment, and it
 * MUST stay comparable with version_compare(): UpdateHookInterface::version()
 * is ordered with that function, so a value it cannot parse silently sorts
 * wrong and runs data migrations out of sequence.
 */
final class CpVersion
{
    /**
     * Bumped by hand on release, together with the entry in the CPalius/version
     * repository. Nothing generates this — a build step that writes it would
     * mean the tarball and the repository could disagree.
     */
    public const VERSION = '2.2.25';

    public const RELEASED_AT = '2026-09-26';

    /**
     * Release code name, for humans. Never parsed, never compared, never part
     * of an upgrade decision — version_compare() reads VERSION and nothing
     * else. It exists so that "Liman" and "1.1.0" can mean the same thing in a
     * changelog without tempting anyone to put a word inside a version string,
     * which is exactly the kind of value ReleaseChecker refuses.
     */
    public const CODENAME = 'Liman';

    /** Release channel this build belongs to: stable | beta. */
    public const CHANNEL = 'stable';

    private function __construct()
    {
    }
}
