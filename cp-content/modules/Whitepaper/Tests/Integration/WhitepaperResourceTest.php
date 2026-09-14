<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Tests\Integration;

use App\Controller\Admin\ResourceAdminController;
use App\Core\Resource\ResourceRegistry;
use App\Tests\Support\IntegrationTestCase;
use Modules\Whitepaper\Config\WhitepaperConfigProvider;
use Modules\Whitepaper\Content\WhitepaperContent;
use Modules\Whitepaper\Entity\WhitepaperDocument;
use Modules\Whitepaper\Entity\WhitepaperSection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The whitepaper after it stopped being 842 lines of PHP heredocs in core.
 *
 * Four properties matter. The public page must render from the database in the
 * shape the theme already consumed, so moving the storage could not break the
 * template. The document must survive a round trip through config, because that
 * is the only thing that lets a database-backed document be deployed. It must
 * appear in the AACP resource machine, since "editable from the panel" is the
 * whole point. And it must do all of that from a MODULE — an installation that
 * builds an ERP has no whitepaper and must not carry its tables.
 */
#[CoversClass(WhitepaperContent::class)]
#[CoversClass(WhitepaperConfigProvider::class)]
final class WhitepaperResourceTest extends IntegrationTestCase
{
    public function testThePublicPageReadsItsChaptersInWeightOrder(): void
    {
        $this->container();

        // Deliberately persisted out of order, and with the heavier chapter
        // created first: insertion order must not decide the page.
        $this->seedSection('en', 'second', 'Second', 20);
        $this->seedSection('en', 'first', 'First', 10);
        $this->seedDocument('en', 'A title', '<p>Intro</p>', 'v9.9.9');
        $this->em()->flush();

        $doc = $this->content()->forLocale('en');

        self::assertSame('A title', $doc['title']);
        self::assertSame('<p>Intro</p>', $doc['intro_html']);
        self::assertSame('v9.9.9', $doc['version']);
        self::assertSame(['first', 'second'], array_column($doc['sections'], 'id'));

        // The theme reads id/title/html off each row; this checks the values
        // actually arrive rather than that the keys exist.
        self::assertSame('First', $doc['sections'][0]['title']);
        self::assertSame('<p>x</p>', $doc['sections'][0]['html']);
    }

    public function testAMissingLocaleFallsBackInsteadOfRenderingNothing(): void
    {
        $this->container();

        $this->seedSection('en', 'only', 'Only chapter', 10);
        $this->seedDocument('en', 'English original', '<p>i</p>', 'v1.0.0');
        $this->em()->flush();

        // One versioned technical document: a Turkish reader is better served
        // the English original than a blank page.
        $doc = $this->content()->forLocale('tr');
        self::assertSame(['only'], array_column($doc['sections'], 'id'));

        // With nothing installed at all it stays empty rather than inventing
        // placeholder prose.
        $this->truncate();
        self::assertSame([], $this->content()->forLocale('en')['sections']);
        self::assertSame('', $this->content()->forLocale('en')['title']);
    }

    public function testConfigExportImportIsAFaithfulRoundTrip(): void
    {
        $this->container();

        $this->seedSection('en', 'alpha', 'Alpha', 10, '<p>A</p>');
        $this->seedSection('en', 'beta', 'Beta', 20, '<p>B</p>');
        $this->seedDocument('en', 'Doc', '<p>Intro</p>', 'v2.0.0');
        $this->em()->flush();

        $provider = $this->provider();
        $exported = $provider->exportDocument('whitepaper.en');

        self::assertContains('whitepaper.en', $provider->documents());
        self::assertTrue($provider->ownsDocument('whitepaper.en'));
        self::assertFalse($provider->ownsDocument('whitepaper.'), 'an empty locale is not a document');

        // Re-importing what was just exported must be a no-op. If it is not,
        // every deploy rewrites rows and every diff is noise.
        self::assertSame([], $provider->diffDocument('whitepaper.en', $exported));

        $provider->importDocument('whitepaper.en', $exported);
        $this->em()->flush();

        self::assertSame($exported, $provider->exportDocument('whitepaper.en'));
    }

    public function testAChapterDroppedFromTheFileIsRemovedAndAnnouncedFirst(): void
    {
        $this->container();

        $this->seedSection('en', 'keep', 'Keep', 10);
        $this->seedSection('en', 'drop', 'Drop', 20);
        $this->seedDocument('en', 'Doc', '<p>i</p>', 'v1.0.0');
        $this->em()->flush();

        $incoming = $this->provider()->exportDocument('whitepaper.en');
        $incoming['sections'] = array_values(array_filter(
            $incoming['sections'],
            static fn (array $row): bool => $row['slug'] !== 'drop',
        ));

        // The deletion has to be visible in the dry run, because an unexported
        // panel edit is exactly what it would destroy.
        self::assertContains('- whitepaper.en/drop', $this->provider()->diffDocument('whitepaper.en', $incoming));

        $this->provider()->importDocument('whitepaper.en', $incoming);
        $this->em()->flush();

        self::assertSame(['keep'], array_column($this->provider()->exportDocument('whitepaper.en')['sections'], 'slug'));
    }

    public function testAModuleEntityReachesTheResourceRegistry(): void
    {
        $container = $this->container();

        /** @var ResourceRegistry $registry */
        $registry = $container->get(ResourceRegistry::class);

        foreach (['whitepaper', 'whitepaper_section'] as $name) {
            $definition = $registry->getByName($name);

            // This is the assertion that proves the move: registration is what
            // produces the AACP screens, and the compile pass used to scan
            // "src/Entity" — a directory no module has — so a module could
            // never own a #[CpResource] at all.
            self::assertNotNull($definition, sprintf('"%s" reached the resource registry from a module', $name));
            self::assertSame('whitepaper', $definition->module, 'the module owns it, core does not');
            self::assertTrue($definition->auditable, 'edits to a published document are worth an audit row');
            self::assertContains('edit', $definition->capabilities, 'the edit screen is capability-guarded');
        }
    }

    /**
     * Renders the generic resource list WITH rows.
     *
     * Every earlier check went through an empty list, so the row markup was
     * unreachable — which is how `_self.cell()` survived inside a <twig:block>,
     * where _self resolves to the component template and the macro is "not
     * defined". The page died the first time a resource had data in it.
     */
    public function testTheGenericListRendersRowsAndNotJustAnEmptyTable(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        $this->seedSection('en', 'pristine-root', 'Pristine Root', 10, '<p>Body</p>');
        $this->seedSection('en', 'security', 'Security by default', 20, '<p>Body</p>');
        $this->em()->flush();

        $response = $this->resourceAdmin()->index('whitepaper_section', new Request());

        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getContent();
        self::assertStringContainsString('pristine-root', $html, 'the row reached the table');
        self::assertStringContainsString('Security by default', $html);

        // #[CpField(label:)] must read the same in the list as in the form.
        self::assertStringContainsString($this->trans('aacp.whitepaper.field.slug'), $html);
        self::assertStringNotContainsString('aacp.whitepaper.field.', $html, 'no raw translation keys in the header');
    }

    public function testTheCreateScreenOffersTheEditorAndSplitsTheColumns(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        $response = $this->resourceAdmin()->new('whitepaper_section');

        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getContent();

        self::assertStringContainsString('data-cp-richtext', $html, 'the body field asks for the editor');
        self::assertStringContainsString('data-aacp-resource-form', $html, 'the toggle script has a root to bind to');

        // Loaded only because this resource has a richtext property; a resource
        // of plain columns must not pay for CKEditor.
        self::assertStringContainsString('cp-editor-init', $html);

        self::assertStringContainsString($this->trans('aacp.resources.form.section_content'), $html);
        self::assertStringContainsString($this->trans('aacp.resources.form.editor_source'), $html);
    }

    private function seedDocument(string $locale, string $title, string $intro, string $version): void
    {
        $document = new WhitepaperDocument($locale, $title);
        $document->setIntroHtml($intro)->setVersion($version);
        $this->em()->persist($document);
    }

    private function seedSection(string $locale, string $slug, string $title, int $weight, string $body = '<p>x</p>'): void
    {
        $section = new WhitepaperSection($locale, $slug, $title);
        $section->setWeight($weight)->setBodyHtml($body);
        $this->em()->persist($section);
    }

    private function truncate(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM cp_whitepaper_sections');
        $connection->executeStatement('DELETE FROM cp_whitepaper_documents');
        $this->em()->clear();
    }

    private function resourceAdmin(): ResourceAdminController
    {
        /** @var ResourceAdminController $controller */
        $controller = $this->container()->get(ResourceAdminController::class);

        return $controller;
    }

    private function trans(string $key): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->container()->get(TranslatorInterface::class);

        return $translator->trans($key);
    }

    private function content(): WhitepaperContent
    {
        /** @var WhitepaperContent $service */
        $service = $this->container()->get(WhitepaperContent::class);

        return $service;
    }

    /**
     * Built directly rather than fetched from the container.
     *
     * The provider is only ever referenced through the tagged iterator that
     * ConfigManager receives, so the container inlines it and there is no
     * service to get. Constructing it here tests the class; that it is
     * REGISTERED as a CMI provider is enforced by lint:container plus the
     * core _instanceof rule on ConfigProviderInterface.
     */
    private function provider(): WhitepaperConfigProvider
    {
        $em = $this->em();

        /** @var \Modules\Whitepaper\Repository\WhitepaperDocumentRepository $documents */
        $documents = $em->getRepository(WhitepaperDocument::class);
        /** @var \Modules\Whitepaper\Repository\WhitepaperSectionRepository $sections */
        $sections = $em->getRepository(WhitepaperSection::class);

        return new WhitepaperConfigProvider($documents, $sections, $em);
    }
}
