<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Module\ModuleEntityMappingResolver;
use Modules\Forum\ForumModule;
use Modules\Media\MediaModule;
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
        // Media has no Entity/ directory — it must not produce a mapping.
        self::assertNull(ModuleEntityMappingResolver::resolve(MediaModule::class));
    }

    public function testResolveManyReturnsOnlyMappableModules(): void
    {
        $mappings = ModuleEntityMappingResolver::resolveMany([
            ForumModule::class,
            MediaModule::class,
            MenuModule::class,
            RoadmapModule::class,
        ]);

        $aliases = array_column($mappings, 'alias');
        sort($aliases);

        // Media drops out (no Entity/); the rest map by Modules{Name} alias.
        self::assertSame(['ModulesForum', 'ModulesMenu', 'ModulesRoadmap'], $aliases);
    }
}
