<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Security\CapabilityRegistry;
use App\Core\Security\RoleConfigManager;
use App\Core\Security\Voter\CPaliusVoter;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A role must not hold the unscoped form of a capability that also exists as .own / .any.
 */
#[CoversClass(CPaliusVoter::class)]
final class RoleCapabilityScopingTest extends IntegrationTestCase
{
    private const SCOPE_SUFFIXES = ['.own', '.any'];

    /** Wildcard role "*" is exempt: it is an explicit grant of everything. */
    public function testNoRoleHoldsAnUnscopedCapabilityThatHasAScopedSibling(): void
    {
        /** @var CapabilityRegistry $registry */
        $registry = $this->container()->get(CapabilityRegistry::class);

        $offences = [];

        foreach ($this->declaredRoleCapabilities() as $roleId => $capabilities) {
            if (\in_array('*', $capabilities, true)) {
                continue;
            }

            foreach ($capabilities as $capability) {
                if ($this->isScoped($capability)) {
                    continue;
                }

                foreach (self::SCOPE_SUFFIXES as $suffix) {
                    if ($registry->has($capability.$suffix)) {
                        $offences[] = sprintf(
                            'role "%s" holds unscoped "%s" while "%s" is registered',
                            $roleId,
                            $capability,
                            $capability.$suffix,
                        );
                        break;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "An unscoped capability bypasses the ownership check in CPaliusVoter.\n"
            ."Grant the \".own\" or \".any\" form instead:\n  ".implode("\n  ", $offences),
        );
    }

    /**
     * The concrete regression: a plain member must not read as a forum
     * moderator. This asserts the decision the controller actually makes.
     */
    public function testPlainMemberIsNotAForumModerator(): void
    {
        $this->pushRequest();
        $this->authenticateAs('member', 'scoping-member@example.test');

        /** @var AuthorizationCheckerInterface $auth */
        $auth = $this->container()->get(AuthorizationCheckerInterface::class);

        // Mirrors ForumFrontController::editPost().
        $isModerator = $auth->isGranted('forum.topic.moderate')
            || $auth->isGranted('forum.post.edit.any');

        self::assertFalse($isModerator, 'A member must not satisfy the moderator branch of editPost().');
        self::assertFalse($auth->isGranted('forum.post.edit'), 'The unscoped capability must not be grantable at all.');

        // The scoped right is still held. It is asserted on the role config
        // rather than through is_granted(): ".own" needs an OwnableInterface
        // subject, and a subject-less check correctly returns false — which is
        // the voter behaving properly, not the capability being missing.
        /** @var RoleConfigManager $roles */
        $roles = $this->container()->get(RoleConfigManager::class);
        self::assertContains(
            'forum.post.edit.own',
            $roles->getCapabilitiesForRole('member'),
            'A member must keep the scoped right to edit their own post.',
        );
    }

    /**
     * @return array<string, list<string>> role id => declared capability names
     */
    private function declaredRoleCapabilities(): array
    {
        $syncDir = \dirname(__DIR__, 3).'/cp-content/config/sync';
        $roles = [];

        foreach (glob($syncDir.'/user.role.*.yaml') ?: [] as $file) {
            /** @var array{role?: array{id?: string, capabilities?: list<string>}} $parsed */
            $parsed = Yaml::parseFile($file);
            $roleId = $parsed['role']['id'] ?? null;
            $capabilities = $parsed['role']['capabilities'] ?? null;

            if (!\is_string($roleId) || !\is_array($capabilities)) {
                continue;
            }

            $roles[$roleId] = array_values(array_filter($capabilities, \is_string(...)));
        }

        self::assertNotSame([], $roles, 'No role files were found — the scoping check would pass vacuously.');

        return $roles;
    }

    private function isScoped(string $capability): bool
    {
        foreach (self::SCOPE_SUFFIXES as $suffix) {
            if (str_ends_with($capability, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
