<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\CapabilityLabeler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class CapabilityLabelerTest extends TestCase
{
    public function testItPrefersTheCapabilitiesCatalogue(): void
    {
        $translator = new Translator('tr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'blog.category.manage' => 'Blog kategori ve etiketlerini yönet',
        ], 'tr', CapabilityLabeler::DOMAIN);

        $labeler = new CapabilityLabeler($translator);

        self::assertSame('Blog kategori ve etiketlerini yönet', $labeler->label('blog.category.manage'));
    }

    public function testItFallsBackToTheMessagesCatalogue(): void
    {
        $translator = new Translator('tr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'capability.node.post.create' => 'Yazı oluştur',
        ], 'tr');

        $labeler = new CapabilityLabeler($translator);

        self::assertSame('Yazı oluştur', $labeler->label('node.post.create'));
    }

    public function testUnknownCapabilitiesKeepTheMachineName(): void
    {
        $labeler = new CapabilityLabeler(new Translator('tr'));

        self::assertSame('unknown.cap', $labeler->label('unknown.cap'));
    }
}
