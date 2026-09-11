<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Module\ModuleContributionCatalog;
use Symfony\Component\Routing\RouterInterface;

/**
 * Post-login / post-register landing. Modules declare account.post_login_route.
 */
final class AccountLandingResolver
{
    public const FALLBACK_ROUTE = 'theme_cpalius_website_home';

    public function __construct(
        private readonly ModuleContributionCatalog $contributions,
        private readonly RouterInterface $router,
    ) {
    }

    public function routeName(): string
    {
        $route = $this->contributions->accountPostLoginRoute();
        if ($route !== null && $this->router->getRouteCollection()->get($route) !== null) {
            return $route;
        }

        return self::FALLBACK_ROUTE;
    }
}
