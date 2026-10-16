<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Settings\SettingsRegistry;
use App\Tests\Support\IntegrationTestCase;
use Modules\Ai\Service\AiProviderConfig;
use Modules\Ai\Service\AiProviders;
use Modules\Ai\Service\AiTranslationException;
use Modules\Ai\Service\AiTranslatorService;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The AI module talks to whichever service the site picked, with that service's own key and model. */
#[CoversNothing]
final class AiProvidersTest extends IntegrationTestCase
{
    /** @var list<array{string, string, string}> method, url, headers + body of each request */
    private array $seen = [];

    private function translator(MockResponse|callable $answer): AiTranslatorService
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->seen[] = [$method, $url, implode('|', $options['headers'] ?? []).'|'.($options['body'] ?? '')];

            return $answer instanceof MockResponse ? $answer : $answer();
        });

        return new AiTranslatorService(
            $this->container()->get(SettingsRegistry::class),
            new NullLogger(),
            $this->container()->get(AiProviderConfig::class),
            $http,
        );
    }

    private function reply(string $content): MockResponse
    {
        return new MockResponse(json_encode(['choices' => [['message' => ['content' => $content]]]], \JSON_THROW_ON_ERROR));
    }

    public function testEachServiceUsesItsOwnAddressKeyAndModel(): void
    {
        $this->container();
        /** @var AiProviderConfig $config */
        $config = $this->container()->get(AiProviderConfig::class);

        try {
            // DeepSeek: OpenAI-style call with a bearer key and its default model.
            $config->save('deepseek', 'sk-deep', '', '');
            $config->save('openai', 'sk-open', 'gpt-4.1-mini', '');
            $config->setActive('deepseek');
            self::assertSame('hello', $this->translator($this->reply('<think>pondering</think>{"reply":"hello"}'))->chat('s', 'u'));
            [$method, $url, $sent] = $this->seen[0];
            self::assertSame(['POST', 'https://api.deepseek.com/chat/completions'], [$method, $url]);
            self::assertStringContainsString('Bearer sk-deep', $sent);
            self::assertStringContainsString('"model":"deepseek-chat"', $sent);
            self::assertStringNotContainsString('sk-open', $sent, 'another service\'s key is never sent');

            // Switching service keeps the first key and uses the new model.
            $config->setActive('openai');
            $this->translator($this->reply('{"reply":"hi"}'))->chat('s', 'u');
            self::assertStringContainsString('Bearer sk-open', $this->seen[1][2]);
            self::assertStringContainsString('gpt-4.1-mini', $this->seen[1][2]);
            self::assertSame('sk-deep', $config->spec('deepseek')['apiKey']);

            // Claude: its own endpoint and headers.
            $config->save('anthropic', 'sk-ant', '', '');
            $config->setActive('anthropic');
            $claude = $this->translator(new MockResponse(json_encode(['content' => [['type' => 'text', 'text' => '{"reply":"selam"}']]], \JSON_THROW_ON_ERROR)));
            self::assertSame('selam', $claude->chat('s', 'u'));
            self::assertSame('https://api.anthropic.com/v1/messages', $this->seen[2][1]);
            self::assertStringContainsString('x-api-key: sk-ant', $this->seen[2][2]);

            // A service that needs no key is ready without one and sends no Authorization header.
            $config->setActive('pollinations');
            $free = $this->translator($this->reply('{"reply":"ok"}'));
            self::assertTrue($free->hasApiKey());
            self::assertSame('ok', $free->chat('s', 'u'));
            self::assertStringNotContainsStringIgnoringCase('authorization', $this->seen[3][2]);

            // A custom server needs an address.
            $config->setActive('custom');
            try {
                $this->translator($this->reply('x'))->chat('s', 'u');
                self::fail('a custom service without an address cannot be called');
            } catch (AiTranslationException $e) {
                self::assertSame('ai.error.no_url', $e->getMessage());
            }

            // Keys are sealed at rest.
            $stored = (string) $this->em()->getConnection()->fetchOne('SELECT setting_value FROM cp_settings WHERE setting_key = ?', [AiProviderConfig::KEY_PROVIDERS]);
            self::assertStringNotContainsString('sk-deep', $stored);
            self::assertTrue($config->forForm('deepseek')['has_key']);
        } finally {
            $this->cleanUp();
        }
    }

    public function testFailuresSayWhatTheServiceSaid(): void
    {
        $this->container();
        /** @var AiProviderConfig $config */
        $config = $this->container()->get(AiProviderConfig::class);

        try {
            $config->save('groq', 'gsk-1', '', '');
            $config->setActive('groq');

            try {
                $this->translator(new MockResponse('{"error":{"message":"You exceeded your current quota"}}', ['http_code' => 429]))->chat('s', 'u');
                self::fail('quota must surface');
            } catch (AiTranslationException $e) {
                self::assertSame('ai.error.quota', $e->getMessage(), 'a 429 that means "out of credit" is a quota problem');
                self::assertStringContainsString('exceeded your current quota', (string) $e->detail);
                self::assertStringContainsString('Groq', (string) $e->detail);
            }

            try {
                $this->translator(new MockResponse('{"error":{"message":"slow down"}}', ['http_code' => 429]))->chat('s', 'u');
                self::fail('rate limit must surface');
            } catch (AiTranslationException $e) {
                self::assertSame('ai.error.rate_limit', $e->getMessage());
            }

            try {
                $this->translator(new MockResponse('{"error":{"message":"bad key"}}', ['http_code' => 401]))->chat('s', 'u');
                self::fail('a rejected key must surface');
            } catch (AiTranslationException $e) {
                self::assertSame('ai.error.unauthorized', $e->getMessage());
            }
        } finally {
            $this->cleanUp();
        }
    }

    public function testCatalogCoversTheServicesTheOwnerAskedFor(): void
    {
        foreach (['deepseek', 'qwen', 'anthropic', 'openai', 'xai', 'openrouter', 'groq', 'gemini'] as $id) {
            self::assertTrue(AiProviders::isKnown($id), $id);
        }
        self::assertSame('paid', AiProviders::find('deepseek')['tier']);
        self::assertSame('free', AiProviders::find('openrouter')['tier']);
        self::assertFalse(AiProviders::find('pollinations')['key']);
    }

    public function testSettingsScreenListsEveryServiceAndSavesPerService(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $controller = $this->container()->get(\Modules\Ai\Controller\Admin\AiSettingsAdminController::class);
        $controller->setContainer($this->container());

        try {
            $html = (string) $controller->index()->getContent();
            foreach (['deepseek', 'qwen', 'anthropic', 'openai', 'xai', 'openrouter'] as $id) {
                self::assertStringContainsString('name="providers['.$id.'][model]"', $html);
            }
            self::assertStringContainsString('name="provider" value="deepseek"', $html);

            $token = $this->container()->get('security.csrf.token_manager')->getToken('admin_ai_settings')->getValue();
            $request = \Symfony\Component\HttpFoundation\Request::create('/admin/ai', 'POST', [
                '_token' => $token,
                'provider' => 'deepseek',
                'providers' => ['deepseek' => ['api_key' => 'sk-form', 'model' => 'deepseek-reasoner', 'base_url' => 'javascript:alert(1)']],
            ]);
            $controller->update($request);

            $config = $this->container()->get(AiProviderConfig::class);
            $spec = $config->spec();
            self::assertSame(['deepseek', 'sk-form', 'deepseek-reasoner', 'https://api.deepseek.com'], [$spec['id'], $spec['apiKey'], $spec['model'], $spec['baseUrl']], 'a non-http address is dropped');
        } finally {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM cp_settings WHERE setting_key IN (?, ?)', [AiProviderConfig::KEY_PROVIDERS, AiProviderConfig::KEY_ACTIVE]);
        $this->container()->get(SettingsRegistry::class)->clearCache();
    }
}
