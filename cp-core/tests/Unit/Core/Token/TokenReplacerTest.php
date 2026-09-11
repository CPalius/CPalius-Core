<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Token;

use App\Core\Settings\SettingsRegistry;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Token\Provider\NodeTokenProvider;
use App\Core\Token\Provider\SiteTokenProvider;
use App\Core\Token\Provider\TermTokenProvider;
use App\Core\Token\Provider\UserTokenProvider;
use App\Core\Token\TokenReplacer;
use App\Core\Token\TokenTypeRegistry;
use App\Entity\Node;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Real provider objects rather than mocks (TokenReplacer and every provider are `final`) —
 * same convention as the T2.3 OriginCache test suite.
 */
#[CoversClass(TokenReplacer::class)]
#[CoversClass(NodeTokenProvider::class)]
#[CoversClass(TermTokenProvider::class)]
#[CoversClass(UserTokenProvider::class)]
#[CoversClass(SiteTokenProvider::class)]
final class TokenReplacerTest extends TestCase
{
    public function testNodeTokensResolveTitleSlugAndDateFormat(): void
    {
        $node = new Node('Merhaba Dünya', 'merhaba-dunya', 'post', 'tr');
        $reflection = new \ReflectionProperty(Node::class, 'createdAt');
        $reflection->setValue($node, new \DateTimeImmutable('2026-03-05'));

        $replacer = $this->replacer();

        self::assertSame(
            '/blog/2026/merhaba-dunya-and-Merhaba Dünya',
            $replacer->replace('/blog/[node:created:Y]/[node:slug]-and-[node:title]', ['node' => $node]),
        );
    }

    public function testNodeCreatedWithoutFormatArgDefaultsToYmd(): void
    {
        $node = new Node('T', 's', 'post', 'en');
        $reflection = new \ReflectionProperty(Node::class, 'createdAt');
        $reflection->setValue($node, new \DateTimeImmutable('2026-03-05'));

        self::assertSame('2026-03-05', $this->replacer()->replace('[node:created]', ['node' => $node]));
    }

    public function testUnknownPropertyIsLeftAsLiteralBracket(): void
    {
        $node = new Node('T', 's', 'post', 'en');

        self::assertSame('[node:nope]', $this->replacer()->replace('[node:nope]', ['node' => $node]));
    }

    public function testMissingSubjectForARequiredTypeIsLeftAsLiteralBracket(): void
    {
        // No 'node' entry in context at all.
        self::assertSame('[node:title]', $this->replacer()->replace('[node:title]', []));
    }

    public function testTermTokens(): void
    {
        $vocabulary = new Vocabulary('channels', 'Channels');
        $term = new Term($vocabulary, 'Video Tutorials', 'video-tutorials', 'en');

        self::assertSame('video-tutorials', $this->replacer()->replace('[term:slug]', ['term' => $term]));
        self::assertSame('Video Tutorials', $this->replacer()->replace('[term:name]', ['term' => $term]));
    }

    public function testUserTokens(): void
    {
        $user = new User('ada@example.com');
        $user->setUsername('ada');

        self::assertSame('ada@example.com', $this->replacer()->replace('[user:mail]', ['user' => $user]));
        self::assertSame('ada', $this->replacer()->replace('[user:display_name]', ['user' => $user]));
    }

    public function testSiteTokenNeedsNoSubjectInContext(): void
    {
        self::assertSame('CPalius CMF Test Site', $this->replacer()->replace('[site:name]', []));
    }

    public function testValidateFlagsUnknownTokenType(): void
    {
        self::assertSame(['unknown_type:nod'], $this->replacer()->validate('/blog/[nod:title]'));
    }

    public function testValidateAcceptsKnownTypesRegardlessOfProperty(): void
    {
        // Property names are not enforced (custom Field API values aren't known at compile time).
        self::assertSame([], $this->replacer()->validate('/blog/[node:whatever_custom_field]'));
    }

    public function testValidateFlagsMalformedBracketSyntax(): void
    {
        self::assertSame(['malformed_syntax'], $this->replacer()->validate('/blog/[node:title'));
    }

    public function testValidateOnACleanPatternReturnsNoErrors(): void
    {
        self::assertSame([], $this->replacer()->validate('/blog/[node:created:Y]/[node:title]'));
    }

    private function replacer(): TokenReplacer
    {
        $settings = $this->createMock(SettingsRegistry::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'core.site_name' ? 'CPalius CMF Test Site' : $default,
        );

        $catalog = new TokenTypeRegistry();
        foreach (['title', 'slug', 'created'] as $property) {
            $catalog->register('node', $property, 'token.node.'.$property);
        }
        $catalog->register('term', 'name', 'token.term.name');
        $catalog->register('term', 'slug', 'token.term.slug');
        $catalog->register('user', 'mail', 'token.user.mail');
        $catalog->register('user', 'display_name', 'token.user.display_name');
        $catalog->register('site', 'name', 'token.site.name');

        return new TokenReplacer(
            [
                new NodeTokenProvider(),
                new TermTokenProvider(),
                new UserTokenProvider(),
                new SiteTokenProvider($settings),
            ],
            $catalog,
        );
    }
}
