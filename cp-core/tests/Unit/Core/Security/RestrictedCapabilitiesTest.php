<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\RestrictedCapabilities;
use PHPUnit\Framework\TestCase;

final class RestrictedCapabilitiesTest extends TestCase
{
    public function testLevels(): void
    {
        self::assertSame('critical', RestrictedCapabilities::level('system.update.manage'));
        self::assertSame('sensitive', RestrictedCapabilities::level('hall.settings.manage'), 'any *.settings.manage is at least sensitive');
        self::assertNull(RestrictedCapabilities::level('forum.post.like'));
        self::assertFalse(RestrictedCapabilities::isRestricted('account.profile.edit'));
    }

    public function testNoDefaultMemberOrEditorRoleHoldsACriticalCapability(): void
    {
        foreach (['member', 'editor'] as $role) {
            $yaml = (string) file_get_contents(__DIR__.'/../../../../../cp-content/config/sync/user.role.'.$role.'.yaml');
            preg_match_all('/^\s+-\s+([a-z0-9_.*]+)\s*$/m', $yaml, $m);
            foreach ($m[1] as $capability) {
                self::assertNotSame('critical', RestrictedCapabilities::level($capability), $role.' must not hold '.$capability);
            }
        }
    }
}
