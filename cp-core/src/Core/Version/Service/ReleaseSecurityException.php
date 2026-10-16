<?php

declare(strict_types=1);

namespace App\Core\Version\Service;

/**
 * Thrown when a release manifest fails signature, freshness or rollback checks.
 * Kept apart from network errors so only real trust failures raise the warning.
 */
final class ReleaseSecurityException extends \RuntimeException
{
}
