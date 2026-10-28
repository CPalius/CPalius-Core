<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Module\ModuleEntityMappingResolver;
use Modules\DnsTools\DnsToolsModule;
use Modules\Forum\ForumModule;
use Modules\Menu\MenuModule;
use Modules\Roadmap\RoadmapModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleEntityMappingResolver::class)]
final class ModuleEntityMappingResolverTest extends TestCase
{
    public function testResolvesForumMapping(): void
    {
        $mapping = ModuleEntityMappingResolver::resolve(ForumModule::class);

        self::assertNotNull($mapping);
        self::assertSame('Modules\Forum\Entity', $mapping['namespace']);
        self::assertSame('ModulesForum', $mapping['alias']);
        self::assertDirectoryExists($mapping['dir']);
    }

    public function testSkipsModulesWithoutEntityDirectory(): void
    {
        // DnsTools has no Entity/ directory — it must not produce a mapping.
        self::assertNull(ModuleEntityMappingResolver::resolve(DnsToolsModule::class));
    }

    public function testResolveManyReturnsOnlyMappableModules(): void
    {
        $mappings = ModuleEntityMappingResolver::resolveMany([
            ForumModule::class,
            DnsToolsModule::class,
            MenuModule::class,
            RoadmapModule::class,
        ]);

        $aliases = array_column($mappings, 'alias');
        sort($aliases);

        // DnsTools drops out (no Entity/); the rest map by Modules{Name} alias.
        self::assertSame(['ModulesForum', 'ModulesMenu', 'ModulesRoadmap'], $aliases);
    }
}
