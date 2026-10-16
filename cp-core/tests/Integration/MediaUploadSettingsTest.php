<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Media\MimeTypeAllowlist;
use App\Tests\Support\IntegrationTestCase;
use Modules\Media\Controller\Admin\MediaAdminController;
use Modules\Media\Controller\Admin\MediaLibraryApiController;
use Modules\Media\Service\MediaUploadSettings;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Media library upload policy: chosen file types and size cap, and the upload answer the screen relies on. */
#[CoversNothing]
final class MediaUploadSettingsTest extends IntegrationTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testCatalogAndRestrictedAllowlist(): void
    {
        $core = new MimeTypeAllowlist();
        self::assertNull($core->extensionFor('application/zip'), 'archives are off unless an administrator opts in');

        $wide = $core->restrictedTo(['png', 'zip', 'rar']);
        self::assertSame('zip', $wide->extensionFor('application/zip'));
        self::assertSame('rar', $wide->extensionFor('application/vnd.rar'));
        self::assertSame('png', $wide->extensionFor('image/png'));
        self::assertNull($wide->extensionFor('image/jpeg'), 'a type that was not chosen is refused');
        self::assertNull($wide->extensionFor('image/svg+xml'));
        self::assertNull($wide->extensionFor('text/html'));
        self::assertNull($wide->restrictedTo(['svg', 'html', 'php', 'exe'])->extensionFor('text/html'), 'dangerous names cannot be switched on');

        self::assertContains('zip', MimeTypeAllowlist::catalog()['archives']);
        self::assertNotContains('svg', array_merge(...array_values(MimeTypeAllowlist::catalog())));
    }

    public function testUploadFollowsTheSavedTypesAndSize(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $controller = $this->container()->get(MediaAdminController::class);
        $controller->setContainer($this->container());
        /** @var MediaUploadSettings $settings */
        $settings = $this->container()->get(MediaUploadSettings::class);
        $dir = sys_get_temp_dir();

        try {
            $png = $dir.'/cp-up-'.bin2hex(random_bytes(4)).'.png';
            file_put_contents($png, base64_decode(self::PNG).random_bytes(16));
            $answer = json_decode((string) $controller->upload($this->post($png, 'dot.png'))->getContent(), true);
            self::assertSame('dot.png', $answer['originalName']);
            self::assertSame($answer['id'], $answer['asset']['id'], 'the screen gets the finished row back');

            $browse = $this->container()->get(MediaLibraryApiController::class);
            $browse->setContainer($this->container());
            $listed = json_decode((string) $browse->browse(new Request(['sort' => 'date_desc']))->getContent(), true);
            self::assertContains($answer['id'], array_column($listed['assets'], 'id'), 'a fresh upload shows in the library');

            $zip = $dir.'/cp-up-'.bin2hex(random_bytes(4)).'.zip';
            $archive = new \ZipArchive();
            $archive->open($zip, \ZipArchive::CREATE);
            $archive->addFromString('a.txt', bin2hex(random_bytes(8)));
            $archive->close();

            try {
                $controller->upload($this->post($zip, 'pack.zip'));
                self::fail('zip must be refused until it is switched on');
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }

            $settings->save([...MimeTypeAllowlist::coreExtensions(), 'zip'], 0);
            $zipAnswer = json_decode((string) $controller->upload($this->post($zip, 'pack.zip'))->getContent(), true);
            self::assertSame('application/zip', $zipAnswer['mimeType']);
            self::assertStringEndsWith('.zip', $zipAnswer['url']);

            $big = $dir.'/cp-up-'.bin2hex(random_bytes(4)).'.png';
            file_put_contents($big, base64_decode(self::PNG).random_bytes(1_200_000));
            $settings->save(MimeTypeAllowlist::coreExtensions(), 1);
            self::assertSame(1048576, $settings->maxBytes(), "size cap is applied");

            try {
                $controller->upload($this->post($big, 'big.png'));
                self::fail('a file above the cap must be refused');
            } catch (BadRequestHttpException $e) {
                self::assertStringContainsString('1', $e->getMessage());
            }
        } finally {
            $this->em()->getConnection()->executeStatement('DELETE FROM cp_settings WHERE setting_key = ?', [MediaUploadSettings::KEY]);
            $this->container()->get(\App\Core\Settings\SettingsRegistry::class)->clearCache(MediaUploadSettings::KEY);
        }
    }

    private function post(string $path, string $clientName): Request
    {
        return Request::create('/admin/media/upload', 'POST', [], [], ['file' => new UploadedFile($path, $clientName, null, null, true)]);
    }
}
