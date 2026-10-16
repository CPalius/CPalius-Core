<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Asset;
use App\Tests\Support\IntegrationTestCase;
use League\Flysystem\FilesystemOperator;
use Modules\Media\Controller\Admin\MediaLibraryApiController;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The JSON endpoints the Studio media screen calls: CSRF is enforced, errors
 * come back as localized 400s, and delete → trash → restore round-trips.
 */
#[CoversNothing]
final class MediaLibraryApiTest extends IntegrationTestCase
{
    private function controller(): MediaLibraryApiController
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        /** @var MediaLibraryApiController $c */
        $c = $this->container()->get(MediaLibraryApiController::class);

        return $c;
    }

    private function token(): string
    {
        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $this->container()->get('security.csrf.token_manager');

        return $csrf->getToken(MediaLibraryApiController::CSRF)->getValue();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, ?string $token = null): Request
    {
        $request = new Request(content: json_encode($body, \JSON_THROW_ON_ERROR));
        $request->setMethod('POST');
        $request->headers->set('X-CSRF-Token', $token ?? $this->token());

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testAMutationWithoutTheCsrfTokenIsRefused(): void
    {
        $response = $this->controller()->createFolder($this->post(['name' => 'X'], 'wrong'));

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $this->data($response));
    }

    public function testFolderErrorsAreReportedAsALocalizedBadRequest(): void
    {
        $controller = $this->controller();
        $controller->createFolder($this->post(['name' => 'Docs']));

        $dup = $controller->createFolder($this->post(['name' => 'docs']));
        self::assertSame(400, $dup->getStatusCode());

        $bad = $controller->createFolder($this->post(['name' => 'a/b']));
        self::assertSame(400, $bad->getStatusCode());
    }

    public function testDeleteGoesToTheMediaTrashAndRestoreBringsItBack(): void
    {
        $controller = $this->controller();

        $hash = hash('sha256', 'api');
        $asset = new Asset($hash.'.png', 'api.png', '2026/10', 'image/png', 3, $hash);
        /** @var FilesystemOperator $storage */
        $storage = $this->container()->get('cpalius_storage');
        $storage->write($asset->getStorageKey(), 'x');
        $this->em()->persist($asset);
        $this->em()->flush();
        $id = (int) $asset->getId();

        $deleted = $controller->deleteAssets($this->post(['ids' => [$id]]));
        self::assertSame(1, $this->data($deleted)['deleted']);

        $request = new Request();
        $trash = $this->data($controller->trash($request))['items'];
        self::assertCount(1, $trash);
        self::assertSame('api.png', $trash[0]['name']);

        $restored = $this->data($controller->restore($this->post(['ids' => [$trash[0]['id']]])));
        self::assertSame(1, $restored['restored']);
        self::assertSame([], $restored['errors']);

        $browse = $this->data($controller->browse(new Request()));
        self::assertSame(['api.png'], array_column($browse['assets'], 'name'));
    }
}
