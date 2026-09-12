<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Integration;

use App\Entity\Node;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use Modules\Importer\Controller\Admin\ImportController;
use Modules\Importer\SourceSystemCatalog;
use Modules\Importer\Storage\ImportFileStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * The import screen, rendered for real.
 *
 * The controller is called directly rather than driven through the browser:
 * asserting that a request is redirected to the login form proves the firewall
 * and nothing about the screen. What is held here is that opening the page
 * changes nothing, that writing needs a deliberate POST with a token, and that
 * the screen offers only what actually works.
 */
#[CoversClass(ImportController::class)]
#[CoversClass(SourceSystemCatalog::class)]
final class ImportScreenTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/sample-export.xml';
    private const UPLOADS = __DIR__.'/../Fixtures/uploads';

    private ?ImportController $controller = null;

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        // Each test starts with an empty upload area: one test's upload showing
        // up in another's listing would make both of them lie.
        $this->clearUploads();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearUploads();

        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->scratch = [];
        $this->controller = null;

        parent::tearDown();
    }

    private function clearUploads(): void
    {
        $dir = $this->storeDirectory();

        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }

    /**
     * Memoised for the length of one test: authenticateAs() persists a user,
     * so booting twice in the same test would try to create a second account
     * on the same address and fail on the unique index rather than on
     * anything the test is about.
     */
    private function boot(string $role = 'admin'): ImportController
    {
        if ($this->controller !== null) {
            return $this->controller;
        }

        $container = $this->container();
        $this->pushRequest();
        $this->authenticateAs($role);

        /** @var ImportController $controller */
        $controller = $container->get(ImportController::class);

        return $this->controller = $controller;
    }

    /**
     * Read out of the rendered form rather than minted from the token manager.
     * That is both closer to what a browser does and a stronger assertion: it
     * proves the page actually ships a token somebody can submit.
     */
    private function token(): string
    {
        $html = (string) $this->boot()->system('wordpress')->getContent();

        if (preg_match('/name="_token" value="([^"]+)"/', $html, $matches) !== 1) {
            self::fail('The import form did not render a CSRF token.');
        }

        return $matches[1];
    }

    /**
     * @param array<string, string> $options
     */
    private function post(string $mode, array $options, int $limit = 50, bool $withToken = true): Request
    {
        $request = new Request([], [
            '_token' => $withToken ? $this->token() : 'not-the-token',
            'mode' => $mode,
            'limit' => (string) $limit,
            'options' => $options,
        ]);
        $request->setMethod('POST');

        return $request;
    }

    private function store(): ImportFileStore
    {
        $this->container();

        return new ImportFileStore($this->storeDirectory());
    }

    private function storeDirectory(): string
    {
        // Tests/Integration -> Tests -> Importer -> modules -> cp-content -> root.
        // Matches the service definition, which splits by environment so a
        // test run never touches what a developer uploaded in dev.
        return \dirname(__DIR__, 5).'/cp-core/var/imports/test';
    }

    /**
     * A POST carrying a file, in UploadedFile's test mode — the supported way
     * to drive an upload without a web server in front of it.
     */
    private function uploadRequest(string $name, string $contents): Request
    {
        $path = sys_get_temp_dir().'/cpalius-screen-up-'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        $request = new Request([], ['_token' => $this->token()], [], [], [
            'export' => new UploadedFile($path, $name, null, null, true),
        ]);
        $request->setMethod('POST');

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function wordpressOptions(): array
    {
        return ['file' => self::FIXTURE, 'uploads' => self::UPLOADS, 'locale' => 'tr'];
    }

    public function testTheIndexOffersWorkingSourcesAndMarksPlannedOnesPlainly(): void
    {
        $html = (string) $this->boot()->index()->getContent();

        self::assertStringContainsString('WordPress', $html);
        self::assertStringContainsString('CSV', $html);
        self::assertStringContainsString('/admin/import/wordpress', $html);
        // A system becomes clickable by having migrations registered, not by
        // anyone editing this screen — XenForo moved from planned to ready by
        // its drivers landing, and nothing here changed.
        self::assertStringContainsString('/admin/import/xenforo', $html);

        // Planned systems are still listed so nobody wonders whether support
        // exists somewhere they have not found — but they are not offered as
        // buttons.
        self::assertStringContainsString('Drupal', $html);
        self::assertStringNotContainsString('/admin/import/drupal', $html);
    }

    public function testTheFormIsBuiltFromWhatTheMigrationsDeclare(): void
    {
        $html = (string) $this->boot()->system('wordpress')->getContent();

        // Not hard-coded in the template: these come from options().
        self::assertStringContainsString('options[file]', $html);
        self::assertStringContainsString('options[uploads]', $html);
        self::assertStringContainsString('options[locale]', $html);

        // Steps are named through the translator rather than in whatever
        // language the migration class happened to be written in. Asserting
        // the absence of both the raw key and the class's own English label
        // holds that without pinning the test to one locale's wording.
        self::assertStringNotContainsString('importer.migration.wordpress_posts', $html, 'an untranslated key would show through');
        self::assertStringNotContainsString('WordPress posts', $html, 'the class label is a fallback, not the label');
    }

    public function testOpeningTheScreenWritesNothing(): void
    {
        $controller = $this->boot();
        $before = $this->rowCounts();

        $controller->index();
        $controller->system('wordpress');

        self::assertSame($before, $this->rowCounts(), 'Visiting a page must not change data.');
    }

    public function testAnUnknownSourceIsNotFound(): void
    {
        $controller = $this->boot();

        $this->expectException(NotFoundHttpException::class);

        $controller->system('doesnotexist');
    }

    public function testWritingWithoutAValidTokenIsRefused(): void
    {
        $controller = $this->boot();

        try {
            $controller->run($this->post('apply', $this->wordpressOptions(), withToken: false), 'wordpress');
            self::fail('A POST without a valid token must be refused.');
        } catch (BadRequestHttpException) {
            // expected
        }

        self::assertSame(0, $this->rowsOf(Node::class));
    }

    public function testADryRunFromTheScreenReportsAndWritesNothing(): void
    {
        $controller = $this->boot();
        $before = $this->rowCounts();

        $html = (string) $controller->run($this->post('dry', $this->wordpressOptions()), 'wordpress')->getContent();

        self::assertStringContainsString('wordpress.posts', $html);
        self::assertSame($before, $this->rowCounts(), 'A dry run from the screen must not write.');
    }

    public function testApplyingFromTheScreenActuallyImports(): void
    {
        $controller = $this->boot();

        $controller->run($this->post('apply', $this->wordpressOptions()), 'wordpress');

        $this->em()->clear();
        self::assertSame(2, $this->rowsOf(Node::class));
    }

    /**
     * A bad path is operator input, so it comes back as a message on the page
     * rather than a stack trace.
     */
    public function testAMissingExportFileIsReportedOnThePage(): void
    {
        $controller = $this->boot();

        $html = (string) $controller->run(
            $this->post('dry', ['file' => '/nope/missing-export.xml', 'uploads' => self::UPLOADS, 'locale' => 'tr']),
            'wordpress',
        )->getContent();

        self::assertStringContainsString('missing-export.xml', $html);
        self::assertSame(0, $this->rowsOf(Node::class));
    }

    public function testTheRowLimitIsEnforcedSoABrowserRequestCannotRunAway(): void
    {
        $controller = $this->boot();

        $controller->run($this->post('apply', $this->wordpressOptions(), limit: 1), 'wordpress');

        $this->em()->clear();
        // One post per step, not the whole export.
        self::assertSame(1, $this->rowsOf(Node::class));
    }

    public function testAnUploadedExportAppearsOnThePageAndCanBeSelected(): void
    {
        $controller = $this->boot();

        $response = $controller->upload($this->uploadRequest('export.xml', (string) file_get_contents(self::FIXTURE)), 'wordpress');

        self::assertSame(302, $response->getStatusCode());

        $html = (string) $controller->system('wordpress')->getContent();
        self::assertStringContainsString('export.xml', $html, 'the upload is listed');
        // And offered in the picker for the option that wants a file.
        self::assertStringContainsString('data-import-picker="opt-file"', $html);
    }

    /**
     * The uploaded export is what actually gets imported — the picker is not
     * decoration, the stored path is a real, usable source.
     */
    public function testAnImportCanRunFromTheUploadedCopy(): void
    {
        $controller = $this->boot();
        $controller->upload($this->uploadRequest('export.xml', (string) file_get_contents(self::FIXTURE)), 'wordpress');

        $stored = $this->store()->all();
        self::assertCount(1, $stored);

        $controller->run($this->post('apply', [
            'file' => $stored[0]->path,
            'uploads' => self::UPLOADS,
            'locale' => 'tr',
        ]), 'wordpress');

        $this->em()->clear();
        self::assertSame(2, $this->rowsOf(Node::class));
    }

    public function testUploadingSomethingThatIsNotAnExportStoresNothing(): void
    {
        $controller = $this->boot();

        $controller->upload($this->uploadRequest('shell.php', '<?php echo 1;'), 'wordpress');

        self::assertSame([], $this->store()->all(), 'a .php upload must not be kept');
    }

    public function testAnUploadCanBeDeletedBecauseItHoldsPersonalData(): void
    {
        $controller = $this->boot();
        $controller->upload($this->uploadRequest('export.xml', (string) file_get_contents(self::FIXTURE)), 'wordpress');

        $stored = $this->store()->all();
        self::assertCount(1, $stored);

        $controller->deleteUpload($this->post('dry', []), 'wordpress', $stored[0]->id);

        self::assertSame([], $this->store()->all());
        self::assertFileDoesNotExist($stored[0]->path);
    }

    /**
     * The guard is an #[IsGranted] attribute the kernel enforces on the
     * request, so this asserts the declaration rather than re-testing
     * Symfony's voter: that the screen is guarded at all, and by the
     * capability the module actually ships.
     */
    public function testTheScreenIsGuardedByTheCapabilityTheModuleShips(): void
    {
        $attributes = (new \ReflectionClass(ImportController::class))
            ->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class);

        self::assertCount(1, $attributes, 'the controller is guarded');
        self::assertSame('importer.run', $attributes[0]->newInstance()->attribute);

        $declared = Yaml::parseFile(__DIR__.'/../../Resources/config/capabilities.yaml');
        self::assertContains('importer.run', $declared['capabilities'] ?? []);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'nodes' => $this->rowsOf(Node::class),
            'users' => $this->rowsOf(User::class),
        ];
    }

    /**
     * @param class-string $entity
     */
    private function rowsOf(string $entity): int
    {
        return \count($this->em()->getRepository($entity)->findBy([]));
    }
}
