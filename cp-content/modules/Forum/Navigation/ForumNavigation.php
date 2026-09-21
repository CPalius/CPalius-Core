<?php

declare(strict_types=1);

namespace Modules\Forum\Navigation;

/**
 * Single source of Studio sidebar order. Every #[CpAdminMenu] priority in this
 * module references one of these bands so the phpBB-style temple (Permissions)
 * and MyBB-style rule/case split stay visible at a glance.
 */
final class ForumNavigation
{
    public const OVERVIEW = 100;
    public const STRUCTURE = 200;
    public const PERMISSIONS = 300;
    public const MODERATION = 400;
    public const PEOPLE = 500;
    public const SETTINGS = 600;

    private function __construct()
    {
    }
}
