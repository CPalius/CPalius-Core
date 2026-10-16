<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\HomepageSettingsController;
use App\Controller\ThemeController;
use App\Core\EventListener\SiteAccessListener;
use App\Core\Homepage\HomepageStore;
use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

/** The homepage engine: editor state, publishing, rendering of overrides, the sandboxed HTML page and site access. */
#[CoversNothing]
final class HomepageEngineTest extends IntegrationTestCase
{
    private function controller(): HomepageSettingsController
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        /** @var HomepageSettingsController $controller */
        $controller = $this->container()->get(HomepageSettingsController::class);
        $controller->setContainer($this->container());

        return $controller;
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        $token = $this->container()->get('security.csrf.token_manager')->getToken('studio_homepage_settings')->getValue();
        $request = Request::create('/admin/homepage/publish', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set('X-CSRF-Token', $token);

        return $request;
    }

    private function locale(): string
    {
        return $this->container()->get(LocaleProvider::class)->getCodes()[0];
    }

    private function renderHero(string $locale): string
    {
        /** @var Environment $twig */
        $twig = $this->container()->get('twig');
        $twig->getLoader()->prependPath(\dirname(__DIR__, 3).'/cp-content/themes/cpalius-website/Resources/views', 'HpTheme');
        $request = Request::create('/'.$locale);
        $request->setLocale($locale);
        $stack = $this->container()->get("request_stack");
        $stack->push($request);

        try {
            return $twig->render('@HpTheme/partials/portal/hero.html.twig', ['portalBlock' => ['id' => 'hero'], 'data' => []]);
        } finally {
            $stack->pop();
        }
    }

    public function testEditorStateExposesEveryLinkAndSwitchOfTheHero(): void
    {
        $html = (string) $this->controller()->index(Request::create('/admin/homepage'))->getContent();

        self::assertSame(1, preg_match('~<script type="application/json" id="hp-state">(.*?)</script>~s', $html, $m));
        $state = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
        $hero = array_values(array_filter($state['sections'], static fn (array $s): bool => $s['id'] === 'hero'))[0];
        $keys = array_column($hero['fields'], 'key');

        foreach (['hero.cta_download@url', 'hero.cta_whitepaper@url', 'hero.github@url', 'hero.title', 'hero.desc'] as $key) {
            self::assertContains($key, $keys);
        }
        self::assertSame(['hero.subtitle', 'hero.cta_download', 'hero.version', 'hero.cta_whitepaper', 'hero.meta', 'hero.code'], array_column($hero['toggles'], 'key'));
        self::assertTrue($state['canScript']);
        self::assertSame('open', $state['access']);
    }

    public function testPublishedOverridesChangeTheLinkTheTextAndRemoveElements(): void
    {
        $controller = $this->controller();
        $loc = $this->locale();
        $before = $this->renderHero($loc);
        self::assertStringContainsString('releases/latest', $before);
        self::assertStringContainsString('hero-code', $before);

        $response = $controller->publish($this->post([
            'engine' => 'blocks',
            'template' => 'showcase',
            'o' => [
                'hero.cta_download@url' => [$loc => 'https://example.com/get'],
                'hero.cta_download' => [$loc => 'Get it'],
                'hero.cta_whitepaper@url' => [$loc => ''],
                'hero.desc' => [$loc => 'My own sentence'],
            ],
            'h' => ['hero.code' => true, 'hero.version' => true],
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $after = $this->renderHero($loc);
        self::assertStringContainsString('href="https://example.com/get"', $after);
        self::assertStringContainsString('Get it', $after);
        self::assertStringContainsString('My own sentence', $after);
        self::assertStringNotContainsString('releases/latest', $after);
        self::assertStringNotContainsString('hero-code', $after);
        self::assertStringNotContainsString('hero-version', $after);
        self::assertStringNotContainsString('btn-secondary', $after, 'an emptied link removes its button');
        self::assertStringNotContainsString('hero-grid', $after);
    }

    public function testAJavascriptAddressCannotBePublishedAsALink(): void
    {
        $controller = $this->controller();
        $loc = $this->locale();
        $controller->publish($this->post(['o' => ['hero.cta_download@url' => [$loc => 'javascript:alert(1)']]]));

        self::assertStringNotContainsString('javascript:', $this->renderHero($loc));
    }

    public function testHtmlEngineRefusesForbiddenScriptAndServesTheRestSandboxed(): void
    {
        $controller = $this->controller();

        $refused = $controller->publish($this->post(['engine' => 'html', 'html' => ['html' => '<p>x</p>', 'css' => '', 'js' => 'fetch("/aacp")']]));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('blocks', $this->container()->get(HomepageStore::class)->engine(), 'a refused page is not stored');

        $ok = $controller->publish($this->post([
            'engine' => 'html',
            'html' => ['html' => '<main onclick="x()"><h1>Hi</h1><script>steal()</script></main>', 'css' => 'h1{color:red}', 'js' => "document.querySelector('h1').textContent = 'Hello';"],
            'seo' => ['title' => [$this->locale() => 'My home'], 'description' => [$this->locale() => 'About my home']],
        ]));
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());

        /** @var ThemeController $theme */
        $theme = $this->container()->get(ThemeController::class);
        $theme->setContainer($this->container());
        $request = Request::create('/'.$this->locale());
        $request->setLocale($this->locale());
        $response = $theme->home($request);
        $body = (string) $response->getContent();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        self::assertStringContainsString('<title>My home</title>', $body);
        self::assertStringContainsString('<h1>Hi</h1>', $body);
        self::assertStringNotContainsString('steal()', $body);
        self::assertStringNotContainsString('onclick', $body);
        self::assertSame(1, preg_match('/nonce="([^"]+)">document\.querySelector/', $body, $m));
        self::assertStringContainsString("script-src 'nonce-".$m[1]."'", $csp);
        self::assertStringContainsString("connect-src 'none'", $csp);
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertMatchesRegularExpression('/sandbox [^;]*allow-scripts/', $csp);
        self::assertStringNotContainsString('allow-same-origin', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringContainsString('rel="canonical"', $body);
    }

    public function testADraftIsRenderedOnlyThroughPreviewAndOnlyForItsEditor(): void
    {
        $this->container();
        $store = $this->container()->get(HomepageStore::class);
        $loc = $this->locale();
        $store->saveDraft('editor-1', ['engine' => 'blocks', 'o' => ['hero.desc' => [$loc => 'Draft only']]]);

        self::assertNull($store->override('hero.desc', $loc), 'the published page is untouched');
        self::assertFalse($store->usePreview('someone-else'));
        self::assertTrue($store->usePreview('editor-1'));
        self::assertSame('Draft only', $store->override('hero.desc', $loc));
    }

    public function testSiteAccessModes(): void
    {
        $this->container();
        $listener = $this->container()->get(SiteAccessListener::class);

        $run = function (string $path, bool $signedIn = false) use ($listener) {
            $request = Request::create($path);
            $request->setSession(new Session(new MockArraySessionStorage()));
            if ($signedIn) {
                $request->cookies->set($request->getSession()->getName(), 'x');
            }
            $event = new RequestEvent($this->container()->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
            $listener->onKernelRequest($event);

            return $event->getResponse();
        };
        $set = function (string $mode): void {
            $row = $this->em()->getRepository(Setting::class)->findOneBy(['settingKey' => SiteAccessListener::KEY]) ?? new Setting(SiteAccessListener::KEY, 'studio_homepage');
            $row->setSettingValue($mode);
            $this->em()->persist($row);
            $this->em()->flush();
            $this->container()->get(SettingsRegistry::class)->clearCache();
        };

        self::assertNull($run('/tr'), 'open: nothing changes');

        $set('login_only');
        $front = $run('/tr');
        self::assertInstanceOf(RedirectResponse::class, $front);
        self::assertSame('/login', $front->headers->get('Location'));
        self::assertSame('noindex, nofollow', $front->headers->get('X-Robots-Tag'));
        self::assertInstanceOf(RedirectResponse::class, $run('/tr/blog'));
        self::assertInstanceOf(RedirectResponse::class, $run('/hesap/kayit'), 'registration is closed too');
        self::assertNull($run('/login'));
        self::assertNull($run('/aacp'));
        self::assertNull($run('/admin/homepage'));
        self::assertNull($run('/hesap/sifremi-unuttum'));
        self::assertNull($run('/api/v1/x'));

        $set('admin_only');
        self::assertInstanceOf(RedirectResponse::class, $run('/'));
        self::assertSame(404, $run('/tr/blog')->getStatusCode());
        self::assertSame(404, $run('/hesap/kayit')->getStatusCode());
        self::assertNull($run('/aacp'));
        self::assertNull($run('/login'));

        $this->authenticateAs('admin');
        $staffFront = $run('/tr', true);
        self::assertInstanceOf(RedirectResponse::class, $staffFront);
        self::assertStringContainsString('/admin', (string) $staffFront->headers->get('Location'));
        self::assertNull($run('/admin/homepage', true));
    }
}
