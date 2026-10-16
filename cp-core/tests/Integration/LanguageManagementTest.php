<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPLanguageController;
use App\Core\Localization\Contract\UiStringTranslatorInterface;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationFileLocator;
use App\Core\Localization\TranslationManager;
use App\Entity\Locale;
use App\Entity\Node;
use App\Repository\LocaleRepository;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Languages screen: completeness, batch fill, placeholder protection, delete guard.
 * Translation files live in a temp dir — the real cp-content/translations is never touched.
 */
#[CoversNothing]
final class LanguageManagementTest extends IntegrationTestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            (new Filesystem())->remove($this->dir);
        }
        parent::tearDown();
    }

    private function locale(string $code, bool $default = false, bool $active = true): Locale
    {
        $l = new Locale($code, strtoupper($code), strtoupper($code));
        $l->setIsDefault($default)->setIsActive($active);
        $this->em()->persist($l);
        $this->em()->flush();
        $this->container()->get(LocaleProvider::class)->invalidate();

        return $l;
    }

    private function manager(): TranslationManager
    {
        $this->dir = sys_get_temp_dir().'/cp-lang-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/messages+intl-icu.tr.yaml', Yaml::dump([
            'a.one' => 'Merhaba',
            'a.two' => 'Sil {name}',
            'a.three' => 'Kaydet',
            'a.empty' => '',
        ]));

        /** @var LocaleProvider $provider */
        $provider = $this->container()->get(LocaleProvider::class);

        return new TranslationManager(new TranslationFileLocator($provider, $this->dir, $this->dir.'/none'), new Filesystem());
    }

    private function controller(?UiStringTranslatorInterface $ai): AACPLanguageController
    {
        $this->pushRequest();
        $this->authenticateAs('admin');

        $controller = new AACPLanguageController(
            $this->container()->get(LocaleRepository::class),
            $this->container()->get(LocaleProvider::class),
            $this->manager(),
            $this->em(),
            $this->container()->get('translator'),
            $ai,
        );
        $controller->setContainer($this->container());

        return $controller;
    }

    private function request(array $body = []): Request
    {
        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $this->container()->get('security.csrf.token_manager');
        $request = new Request(content: json_encode($body));
        $request->setMethod('POST');
        $request->headers->set('X-CSRF-Token', $csrf->getToken(AACPLanguageController::CSRF)->getValue());

        return $request;
    }

    private function fakeAi(\Closure $translate): UiStringTranslatorInterface
    {
        return new class($translate) implements UiStringTranslatorInterface {
            public function __construct(private readonly \Closure $fn)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function batchSize(): int
            {
                return 2;
            }

            public function translateBatch(string $sourceLocale, string $targetLocale, array $strings): array
            {
                return ($this->fn)($strings);
            }
        };
    }

    public function testCompletenessAndMissingIgnoreStringsTheSourceLeavesEmpty(): void
    {
        $this->locale('tr', true);
        $this->locale('de');
        $manager = $this->manager();

        self::assertSame(0, $manager->completeness('tr', ['de'])['de']['percent']);
        self::assertCount(3, $manager->missing('de', 'tr', 10), 'a.empty has nothing to translate');

        $manager->updateMany('core:messages+intl-icu', 'de', ['a.one' => 'Hallo']);

        self::assertSame(33, $manager->completeness('tr', ['de'])['de']['percent']);
        self::assertSame(['a.two', 'a.three'], array_column($manager->missing('de', 'tr', 10), 'key'));
        self::assertSame(['a.three'], array_column($manager->missing('de', 'tr', 10, offset: 1), 'key'));
    }

    public function testPlaceholdersAndMarkupMustSurviveTranslation(): void
    {
        self::assertTrue(AACPLanguageController::sameMarkers('Sil {name}', 'Delete {name}'));
        self::assertFalse(AACPLanguageController::sameMarkers('Sil {name}', 'Delete {nom}'));
        self::assertFalse(AACPLanguageController::sameMarkers('Sil {name}', 'Delete'));
        self::assertTrue(AACPLanguageController::sameMarkers('<b>Kaydet</b> %s', '<b>Save</b> %s'));
        self::assertFalse(AACPLanguageController::sameMarkers('<b>Kaydet</b>', 'Save'));
        self::assertFalse(AACPLanguageController::sameMarkers('{n, plural, one {x} other {y}}', '{n, plural, one {a} other {b}}'));
    }

    public function testAiBatchWritesOnlySafeTranslationsAndCountsTheRest(): void
    {
        $this->locale('tr', true);
        $this->locale('de');

        $ai = $this->fakeAi(static fn (array $s): array => array_map(
            static fn (string $v): string => str_contains($v, '{name}') ? 'Löschen' : $v.' (de)',
            $s,
        ));
        $controller = $this->controller($ai);

        $first = json_decode((string) $controller->aiTranslate('de', $this->request(['offset' => 0]))->getContent(), true);
        // batch of 2: "Merhaba" ok, "Sil {name}" lost its placeholder -> rejected
        self::assertSame(1, $first['translated']);
        self::assertSame(1, $first['failed']);
        self::assertFalse($first['finished']);

        $second = json_decode((string) $controller->aiTranslate('de', $this->request(['offset' => $first['failed']]))->getContent(), true);
        self::assertSame(1, $second['translated']);

        $third = json_decode((string) $controller->aiTranslate('de', $this->request(['offset' => 1]))->getContent(), true);
        self::assertTrue($third['finished']);

        $de = Yaml::parseFile($this->dir.'/messages+intl-icu.de.yaml');
        self::assertSame('Merhaba (de)', $de['a.one']);
        self::assertSame('Kaydet (de)', $de['a.three']);
        self::assertArrayNotHasKey('a.two', array_filter($de, static fn (string $v): bool => $v !== ''));
    }

    public function testAiIsRefusedWithoutAProvider(): void
    {
        $this->locale('tr', true);
        $this->locale('de');

        self::assertSame(400, $this->controller(null)->aiTranslate('de', $this->request())->getStatusCode());
    }

    public function testAiIsRefusedForTheDefaultLanguage(): void
    {
        $this->locale('tr', true);
        $this->locale('de');

        $ai = $this->fakeAi(static fn (array $s): array => $s);
        self::assertSame(400, $this->controller($ai)->aiTranslate('tr', $this->request())->getStatusCode());
    }

    public function testDeleteIsRefusedForTheDefaultAndForLanguagesWithContent(): void
    {
        $default = $this->locale('tr', true);
        $de = $this->locale('de');
        $fr = $this->locale('fr');
        $controller = $this->controller(null);

        $form = static function (Request $r): Request {
            $r->request->set('_token', $r->headers->get('X-CSRF-Token'));

            return $r;
        };

        $controller->delete((int) $default->getId(), $form($this->request()));
        self::assertNotNull($this->em()->find(Locale::class, $default->getId()));

        $node = new Node('Hallo', 'hallo', 'page', 'de');
        $this->em()->persist($node);
        $this->em()->flush();
        $controller->delete((int) $de->getId(), $form($this->request()));
        self::assertNotNull($this->em()->find(Locale::class, $de->getId()), 'content still uses it');

        $frId = (int) $fr->getId();
        $controller->delete($frId, $form($this->request()));
        self::assertNull($this->em()->find(Locale::class, $frId));
    }
}
