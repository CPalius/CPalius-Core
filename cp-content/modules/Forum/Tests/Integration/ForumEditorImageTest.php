<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration;

use App\Entity\Asset;
use App\Tests\Support\IntegrationTestCase;
use Modules\Forum\Controller\ForumEditorImageController;
use Modules\Forum\Controller\ForumFrontController;
use Modules\Forum\Entity\ForumSection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Composer image upload is a write that happens before the post exists.
 * What is held here is the HTTP contract: the new-topic form exposes the
 * upload endpoint, a member with upload rights gets a stored Asset URL,
 * and CSRF / a missing file / a non-image do not.
 */
#[CoversClass(ForumEditorImageController::class)]
final class ForumEditorImageTest extends IntegrationTestCase
{
    /** Complete 1×1 GIF — finfo reports image/gif. */
    private const REAL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    /** @var list<string> */
    private array $scratch = [];

    private ?ForumSection $section = null;

    private ?Request $request = null;

    private ?string $composerHtml = null;

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->scratch = [];
        $this->section = null;
        $this->request = null;
        $this->composerHtml = null;

        parent::tearDown();
    }

    public function testNewTopicComposerExposesImageUpload(): void
    {
        $html = $this->composerHtml();

        self::assertStringContainsString('data-forum-editor', $html);
        self::assertStringContainsString('data-image-upload-url', $html);
        self::assertStringContainsString('/forums/editor-image', $html);
        self::assertStringContainsString('data-image-section="'.(int) $this->section()->getId().'"', $html);
        self::assertNotSame('', $this->csrf());
    }

    public function testAMemberCanUploadAnImageAndGetsAPublicUrl(): void
    {
        $response = $this->controller()->upload($this->post($this->csrf(), (int) $this->section()->getId(), $this->gif('dot.gif')));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertMatchesRegularExpression('#^/uploads/\d{4}/\d{2}/[0-9a-f]{64}\.gif$#', (string) ($payload['url'] ?? ''));
        self::assertCount(1, $this->em()->getRepository(Asset::class)->findAll());
    }

    public function testAMissingTokenIsRefusedAndWritesNothing(): void
    {
        $response = $this->controller()->upload($this->post('not-a-token', (int) $this->section()->getId(), $this->gif('dot.gif')));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->em()->getRepository(Asset::class)->findAll());
    }

    public function testATextFileIsRefused(): void
    {
        $response = $this->controller()->upload($this->post($this->csrf(), (int) $this->section()->getId(), $this->text('notes.txt')));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertNotSame('', $payload['error']['message'] ?? '');
        self::assertSame([], $this->em()->getRepository(Asset::class)->findAll());
    }

    public function testAMissingFileIsRefused(): void
    {
        $response = $this->controller()->upload($this->post($this->csrf(), (int) $this->section()->getId()));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->em()->getRepository(Asset::class)->findAll());
    }

    private function controller(): ForumEditorImageController
    {
        $this->boot();

        /** @var ForumEditorImageController $controller */
        $controller = $this->container()->get(ForumEditorImageController::class);

        return $controller;
    }

    private function boot(): void
    {
        if ($this->section instanceof ForumSection) {
            return;
        }

        $this->container();
        $this->request = $this->pushRequest();
        $this->request->setLocale('tr');
        $this->authenticateAs('member');
        $this->section();
    }

    private function composerHtml(): string
    {
        if ($this->composerHtml !== null) {
            return $this->composerHtml;
        }

        $this->boot();

        $request = $this->request;
        if (!$request instanceof Request) {
            self::fail('Composer render needs a request on the stack.');
        }

        /** @var ForumFrontController $front */
        $front = $this->container()->get(ForumFrontController::class);
        $this->composerHtml = (string) $front->newTopic($request, $this->section()->getSlug())->getContent();

        return $this->composerHtml;
    }

    private function section(): ForumSection
    {
        if ($this->section instanceof ForumSection) {
            return $this->section;
        }

        $section = new ForumSection('genel', 'genel', 'tr', 'Genel');
        $this->em()->persist($section);
        $this->em()->flush();
        $this->section = $section;

        return $section;
    }

    private function csrf(): string
    {
        if (preg_match('/data-image-upload-token="([^"]+)"/', $this->composerHtml(), $matches) !== 1) {
            self::fail('The composer did not render an image upload token.');
        }

        return $matches[1];
    }

    private function post(string $token, int $sectionId, ?UploadedFile $file = null): Request
    {
        $request = new Request(request: [
            '_token' => $token,
            'section_id' => (string) $sectionId,
        ]);
        $request->setMethod('POST');
        if ($file instanceof UploadedFile) {
            $request->files->set('upload', $file);
        }

        return $request;
    }

    private function gif(string $name): UploadedFile
    {
        return $this->upload($name, self::REAL_GIF);
    }

    private function text(string $name): UploadedFile
    {
        return $this->upload($name, 'not an image');
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = sys_get_temp_dir().'/cp-forum-img-'.bin2hex(random_bytes(8));
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
