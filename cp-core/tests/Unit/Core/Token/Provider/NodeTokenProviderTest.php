<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Token\Provider;

use App\Core\Token\Provider\NodeTokenProvider;
use App\Entity\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeTokenProvider::class)]
final class NodeTokenProviderTest extends TestCase
{
    public function testSupportsOnlyNode(): void
    {
        $provider = new NodeTokenProvider();

        self::assertTrue($provider->supports('node'));
        self::assertFalse($provider->supports('term'));
    }

    public function testResolveReturnsNullForANonNodeSubject(): void
    {
        self::assertNull((new NodeTokenProvider())->resolve('title', new \stdClass(), null));
    }

    public function testCustomFieldValuePassesThroughByRawKey(): void
    {
        $node = new Node('Title', 'slug', 'post', 'en');
        $reflection = new \ReflectionProperty(Node::class, 'data');
        $reflection->setValue($node, ['excerpt' => 'A short summary.']);

        self::assertSame('A short summary.', (new NodeTokenProvider())->resolve('excerpt', $node, null));
    }

    public function testUnsupportedComplexCustomFieldValueReturnsNull(): void
    {
        $node = new Node('Title', 'slug', 'post', 'en');
        $reflection = new \ReflectionProperty(Node::class, 'data');
        $reflection->setValue($node, ['gallery' => ['a.jpg', 'b.jpg']]);

        self::assertNull((new NodeTokenProvider())->resolve('gallery', $node, null));
    }

    public function testAuthorAndCategoryFallBackToEmptyStringWhenUnset(): void
    {
        $node = new Node('Title', 'slug', 'post', 'en');

        self::assertSame('', (new NodeTokenProvider())->resolve('author', $node, null));
        self::assertSame('', (new NodeTokenProvider())->resolve('category', $node, null));
    }
}
