<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Token;

use App\Core\Token\TokenTypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenTypeRegistry::class)]
final class TokenTypeRegistryTest extends TestCase
{
    public function testRegisterAndHasType(): void
    {
        $registry = new TokenTypeRegistry();

        self::assertFalse($registry->hasType('node'));

        $registry->register('node', 'title', 'token.node.title');

        self::assertTrue($registry->hasType('node'));
        self::assertFalse($registry->hasType('term'));
    }

    public function testPropertiesForReturnsLabelsForTheType(): void
    {
        $registry = new TokenTypeRegistry();
        $registry->register('node', 'title', 'token.node.title');
        $registry->register('node', 'slug', 'token.node.slug');
        $registry->register('user', 'mail', 'token.user.mail');

        self::assertSame(['title' => 'token.node.title', 'slug' => 'token.node.slug'], $registry->propertiesFor('node'));
        self::assertSame([], $registry->propertiesFor('unknown'));
    }

    public function testAllReturnsTheFullCatalog(): void
    {
        $registry = new TokenTypeRegistry();
        $registry->register('node', 'title', 'token.node.title');
        $registry->register('site', 'name', 'token.site.name');

        self::assertSame([
            'node' => ['title' => 'token.node.title'],
            'site' => ['name' => 'token.site.name'],
        ], $registry->all());
    }
}
