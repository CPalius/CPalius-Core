<?php

declare(strict_types=1);

namespace App\Core\Version\Twig;

use App\Core\Version\CpVersion;
use App\Core\Version\ReleaseChecker;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Data source for {{ cp_version() }} and {{ cp_release_status() }}.
 */
final class VersionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ReleaseChecker $releases,
    ) {
    }

    public function version(): string
    {
        return CpVersion::VERSION;
    }

    /**
     * Last known release state, or null when no check has succeeded yet.
     *
     * Templates must render nothing for null rather than "up to date" — a fresh
     * installation whose cron has not run yet knows nothing, and claiming it is
     * current would hide a security release.
     *
     * @return array{version: string, released_at: ?string, critical: bool, notes_url: ?string, checked_at: ?string, outdated: bool}|null
     */
    public function releaseStatus(): ?array
    {
        return $this->releases->status();
    }
}
