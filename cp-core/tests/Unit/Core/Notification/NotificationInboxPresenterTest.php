<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Notification;

use App\Core\Notification\Entity\Notification;
use App\Core\Notification\NotificationInboxPresenter;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NotificationInboxPresenter::class)]
final class NotificationInboxPresenterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'relative' => ['/tr/forum/konu', '/tr/forum/konu'];
        yield 'https' => ['https://example.com/a', 'https://example.com/a'];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'protocol-relative' => ['//evil.example/x', null];
        yield 'newline' => ["java\nscript:alert(1)", null];
    }

    #[DataProvider('urls')]
    public function testSanitizeUrl(string $raw, ?string $expected): void
    {
        self::assertSame($expected, NotificationInboxPresenter::sanitizeUrl($raw));
    }

    public function testRedirectTargetRejectsOffSiteDataUrl(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $name): string => '/'.$name,
        );

        $presenter = new NotificationInboxPresenter($translator, $urls);
        $user = $this->createMock(User::class);
        $notification = new Notification($user, 'forum.reply', [
            'url' => 'https://evil.example/phish',
            'topic_id' => 4,
            'topic_slug' => 'hello',
        ]);

        self::assertSame('/account_notifications', $presenter->redirectTarget($notification));
    }

    public function testDestinationPrefersRelativeDataUrl(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('ok');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/account_notifications');

        $presenter = new NotificationInboxPresenter($translator, $urls);
        $user = $this->createMock(User::class);
        $notification = new Notification($user, 'messages.new', [
            'url' => '/tr/messages/abcdabcdabcdabcd',
        ]);

        self::assertSame('/tr/messages/abcdabcdabcdabcd', $presenter->destination($notification));
    }
}
