<?php

declare(strict_types=1);

namespace App\Core\Security\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Front-office security helpers that refuse to start a PHP session (Law 6.4).
 */
final class SessionSafeSecurityExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_user', [SessionSafeSecurityRuntime::class, 'user']),
            new TwigFunction('cp_is_granted', [SessionSafeSecurityRuntime::class, 'isGranted']),
            new TwigFunction('cp_flashes', [SessionSafeSecurityRuntime::class, 'flashes']),
        ];
    }
}
