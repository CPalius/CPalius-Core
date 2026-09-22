<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authentication\DefaultAuthenticationSuccessHandler;

/**
 * Confines the post-login redirect to this origin. Relative paths pass; absolute URLs must match scheme+host.
 */
final class SameOriginAuthenticationSuccessHandler extends DefaultAuthenticationSuccessHandler
{
    protected function determineTargetUrl(Request $request): string
    {
        $target = parent::determineTargetUrl($request);

        if ($this->isSameOrigin($request, $target) && LoginTargetPath::isNavigable($target)) {
            return $target;
        }

        $this->logger?->warning('Refused a post-login redirect.', [
            'target' => $target,
            'origin' => $request->getSchemeAndHttpHost(),
        ]);

        return $this->options['default_target_path'];
    }

    private function isSameOrigin(Request $request, string $target): bool
    {
        if ($target === '') {
            return false;
        }

        if (str_starts_with($target, '//') || str_starts_with($target, '\\\\')) {
            return false;
        }

        if (str_starts_with($target, '/')) {
            return true;
        }

        $origin = $request->getSchemeAndHttpHost();

        return $target === $origin || str_starts_with($target, $origin.'/');
    }
}
