<?php

declare(strict_types=1);

namespace App\Core\Security\Reauth;

/**
 * Marks a controller action that hands over the site (applying an update, restoring a backup, installing code,
 * changing who may do what). The action runs only if the signed-in person proved who they are within the last
 * few minutes, by password and, when they have one, their second factor. A stolen session cookie, an unlocked
 * laptop or a hijacked tab is then not enough to reach these.
 *
 * Use on an action, or on a controller class to cover all its actions.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class RequiresRecentAuth
{
    /**
     * @param bool $writesOnly true for an action that serves a form on GET and saves it on POST: only the save
     *                         demands a fresh confirmation, opening the form does not
     */
    public function __construct(public readonly bool $writesOnly = false)
    {
    }
}
