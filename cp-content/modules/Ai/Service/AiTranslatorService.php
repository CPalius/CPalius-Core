<?php

declare(strict_types=1);

namespace Modules\Ai\Service;

use App\Core\Settings\SettingsRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Groq / Gemini first. HTTP 402 on a billed Gemini project falls back to MyMemory
 * (no key). Never logs the API key. Failures must not roll back the original publish.
 */
final class AiTranslatorService
{
    private const DEFAULT_TIMEOUT = 25;

    private const GEMINI_MODELS = [
        'gemini-3.5-flash-lite',
        'gemini-flash-lite-latest',
        'gemini-3.5-flash',
    ];

    private const GROQ_MODELS = [
        'llama-3.1-8b-instant',
        'llama-3.3-70b-versatile',
    ];

    private const MODEL_ALIASES = [
        'gemini-flash-latest' => 'gemini-3.5-flash-lite',
        'gemini-2.0-flash-lite' => 'gemini-3.5-flash-lite',
        'gemini-2.0-flash' => 'gemini-3.5-flash-lite',
        'gemini-2.5-flash-lite' => 'gemini-3.5-flash-lite',
        'gemini-2.5-flash' => 'gemini-3.5-flash-lite',
        'gemini-2.5-pro' => 'gemini-3.5-flash-lite',
        'gemini-1.5-flash' => 'gemini-3.5-flash-lite',
        'gemini-1.5-pro' => 'gemini-3.5-flash-lite',
        'gemini-pro' => 'gemini-3.5-flash-lite',
    ];

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) $this->settings->get('ai.enabled', '1') === '1';
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled();
    }

    public function translate(
        string $sourceLocale,
        string $targetLocale,
        string $title,
        string $body,
        string $description = '',
        string $excerpt = '',
    ): AiTranslationResult {
        if (!$this->isConfigured()) {
            throw new AiTranslationException('ai.error.not_configured');
        }

        if ($this->apiKey() !== '') {
            try {
                $prompt = $this->userPrompt($sourceLocale, $targetLocale, $title, $body, $description, $excerpt);
                $raw = $this->usesGroq()
                    ? $this->completeGroq($prompt)
                    : $this->completeGemini($prompt);

                return $this->parsePayload($raw);
            } catch (AiTranslationException $e) {
                if ($e->getMessage() === 'ai.error.not_configured') {
                    throw $e;
                }
                $this->logger->warning('AI billed provider failed, using the free translator.', [
                    'error' => $e->getMessage(),
                    'status' => $e->getCode(),
                ]);
            }
        }

        return $this->translateFree($sourceLocale, $targetLocale, $title, $body, $description, $excerpt);
    }

    private function provider(): string
    {
        $provider = strtolower(trim((string) $this->settings->get('ai.provider', 'gemini')));

        return $provider === 'groq' ? 'groq' : 'gemini';
    }

    private function apiKey(): string
    {
        return trim((string) $this->settings->get('ai.api_key', ''));
    }

    private function timeout(): float
    {
        $timeout = (int) $this->settings->get('ai.timeout', self::DEFAULT_TIMEOUT);

        return max(5, min(60, $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT));
    }

    private function usesGroq(): bool
    {
        $model = strtolower($this->model());
        if (str_starts_with($model, 'llama-') || str_starts_with($model, 'mixtral-') || str_starts_with($model, 'gemma2-')) {
            return true;
        }

        return $this->provider() === 'groq' && !str_starts_with($model, 'gemini-');
    }

    private function model(): string
    {
        $configured = trim((string) $this->settings->get('ai.model', ''));
        $configured = self::MODEL_ALIASES[$configured] ?? $configured;
        if (in_array($configured, self::GEMINI_MODELS, true) || in_array($configured, self::GROQ_MODELS, true)) {
            return $configured;
        }

        return $this->provider() === 'groq' ? self::GROQ_MODELS[0] : self::GEMINI_MODELS[0];
    }

    private function completeGemini(string $userPrompt, ?string $modelOverride = null, bool $retried = false): string
    {
        $model = $modelOverride ?? $this->model();
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';

        $client = HttpClient::create(['timeout' => $this->timeout()]);
        try {
            $response = $client->request('POST', $url, [
                'timeout' => $this->timeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-goog-api-key' => $this->apiKey(),
                ],
                'json' => [
                    'systemInstruction' => [
                        'parts' => [['text' => $this->systemPrompt()]],
                    ],
                    'contents' => [
                        ['parts' => [['text' => $userPrompt]]],
                    ],
                    'generationConfig' => $this->geminiGenerationConfig($model),
                ],
            ]);

            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('AI Gemini timed out.', ['error' => $e->getMessage(), 'model' => $model]);
            throw new AiTranslationException('ai.error.timeout', 0, $e);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('AI Gemini request failed.', ['error' => $e->getMessage(), 'model' => $model]);
            throw new AiTranslationException('ai.error.unreachable', 0, $e);
        }

        if ($status === 404 && !$retried) {
            $suggested = $this->suggestedGeminiModel((string) ($payload['error']['message'] ?? ''));
            if ($suggested !== null && $suggested !== $model) {
                $this->logger->warning('AI Gemini model gone, retrying the replacement Google named.', [
                    'from' => $model,
                    'to' => $suggested,
                ]);

                return $this->completeGemini($userPrompt, $suggested, true);
            }
        }

        if ($status >= 400) {
            $this->throwForHttpStatus($status, (string) ($payload['error']['message'] ?? ('HTTP '.$status)), 'Gemini', $model);
        }

        $text = $this->extractGeminiText($payload);
        if ($text === '') {
            $firstPart = $payload['candidates'][0]['content']['parts'][0] ?? null;
            $this->logger->warning('AI Gemini returned an empty candidate.', [
                'finish' => $payload['candidates'][0]['finishReason'] ?? null,
                'block' => $payload['promptFeedback']['blockReason'] ?? null,
                'part_keys' => \is_array($firstPart) ? array_keys($firstPart) : [],
            ]);
            throw new AiTranslationException('ai.error.empty');
        }

        return $text;
    }

    private function completeGroq(string $userPrompt): string
    {
        $client = HttpClient::create(['timeout' => $this->timeout()]);
        try {
            $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                'timeout' => $this->timeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$this->apiKey(),
                ],
                'json' => [
                    'model' => $this->model(),
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
            ]);

            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('AI Groq timed out.', ['error' => $e->getMessage()]);
            throw new AiTranslationException('ai.error.timeout', 0, $e);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('AI Groq request failed.', ['error' => $e->getMessage()]);
            throw new AiTranslationException('ai.error.unreachable', 0, $e);
        }

        if ($status >= 400) {
            $this->throwForHttpStatus($status, (string) ($payload['error']['message'] ?? ('HTTP '.$status)), 'Groq', $this->model());
        }

        $text = $payload['choices'][0]['message']['content'] ?? null;
        if (!\is_string($text) || trim($text) === '') {
            $this->logger->warning('AI Groq returned an empty completion.');
            throw new AiTranslationException('ai.error.empty');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractGeminiText(array $payload): string
    {
        $parts = $payload['candidates'][0]['content']['parts'] ?? [];
        if (!\is_array($parts)) {
            return '';
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (!\is_array($part) || !isset($part['text']) || !\is_string($part['text'])) {
                continue;
            }
            if (($part['thought'] ?? false) === true) {
                continue;
            }
            $chunks[] = $part['text'];
        }

        return trim(implode('', $chunks));
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiGenerationConfig(string $model): array
    {
        $config = [
            'temperature' => 0.2,
            'maxOutputTokens' => 8192,
        ];

        $normalized = strtolower($model);
        if (
            str_contains($normalized, '2.5')
            || str_contains($normalized, '3.5')
            || str_contains($normalized, 'flash-latest')
            || str_contains($normalized, 'thinking')
            || str_contains($normalized, 'gemini-3')
        ) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        return $config;
    }

    private function suggestedGeminiModel(string $message): ?string
    {
        if (preg_match('/use models\/(gemini-[a-z0-9.\-]+)/i', $message, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function throwForHttpStatus(int $status, string $message, string $vendor, string $model): never
    {
        $key = match ($status) {
            401, 403 => 'ai.error.unauthorized',
            402 => 'ai.error.quota',
            404 => 'ai.error.model_gone',
            429 => 'ai.error.rate_limit',
            default => 'ai.error.unreachable',
        };

        $this->logger->warning('AI '.$vendor.' HTTP error.', [
            'status' => $status,
            'model' => $model,
            'message' => $message,
        ]);

        throw new AiTranslationException($key, $status);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a professional translator for a multilingual CMS.
Translate only human-visible text. Do not alter HTML tags, attributes, URLs, code, or Markdown structure.
Keep <p>, <br>, <a>, <code>, <pre>, <ul>, <ol>, <li>, <blockquote>, <img>, <h1>-<h6>, <strong>, <em>, <hr>, <table> and every other tag exactly as given.
Do not add commentary. Reply with a single JSON object whose keys are title, description, excerpt, body.
PROMPT;
    }

    private function userPrompt(
        string $sourceLocale,
        string $targetLocale,
        string $title,
        string $body,
        string $description,
        string $excerpt,
    ): string {
        $payload = json_encode([
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'title' => $title,
            'description' => $description,
            'excerpt' => $excerpt,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "Translate this JSON from {$this->languageName($sourceLocale)} to {$this->languageName($targetLocale)}.\n".$payload;
    }

    private function languageName(string $code): string
    {
        return match (strtolower($code)) {
            'tr' => 'Turkish',
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            default => $code,
        };
    }

    private function translateFree(
        string $sourceLocale,
        string $targetLocale,
        string $title,
        string $body,
        string $description,
        string $excerpt,
    ): AiTranslationResult {
        $translatedTitle = $this->translateFreeField($sourceLocale, $targetLocale, $title, false);
        $translatedBody = $this->translateFreeField($sourceLocale, $targetLocale, $body, true);
        if ($translatedTitle === '' || trim(strip_tags($translatedBody)) === '') {
            throw new AiTranslationException('ai.error.quota');
        }

        return new AiTranslationResult(
            title: $translatedTitle,
            body: $translatedBody,
            description: $this->translateFreeField($sourceLocale, $targetLocale, $description, false),
            excerpt: $this->translateFreeField($sourceLocale, $targetLocale, $excerpt, false),
        );
    }

    private function translateFreeField(string $sourceLocale, string $targetLocale, string $value, bool $html): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!$html || !str_contains($value, '<')) {
            return $this->myMemory($sourceLocale, $targetLocale, $value);
        }

        $parts = preg_split('/(<[^>]+>)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $this->myMemory($sourceLocale, $targetLocale, $value);
        }

        $out = '';
        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part, '<') || trim($part) === '') {
                $out .= $part;
                continue;
            }
            if (preg_match('/^(\s*)(.*?)(\s*)$/s', $part, $matches) !== 1) {
                $out .= $this->myMemory($sourceLocale, $targetLocale, $part);
                continue;
            }
            $out .= $matches[1].$this->myMemory($sourceLocale, $targetLocale, $matches[2]).$matches[3];
        }

        return $out;
    }

    private function myMemory(string $sourceLocale, string $targetLocale, string $text): string
    {
        $translated = '';
        foreach ($this->myMemoryChunks($text) as $chunk) {
            $translated .= $this->myMemoryRequest($sourceLocale, $targetLocale, $chunk);
        }

        return trim($translated);
    }

    /**
     * @return list<string>
     */
    private function myMemoryChunks(string $text): array
    {
        $max = 450;
        if (strlen($text) <= $max) {
            return [$text];
        }

        $chunks = [];
        $rest = $text;
        while ($rest !== '') {
            if (strlen($rest) <= $max) {
                $chunks[] = $rest;
                break;
            }
            $slice = substr($rest, 0, $max);
            $break = max(
                (int) strrpos($slice, "\n"),
                (int) strrpos($slice, '. '),
                (int) strrpos($slice, ' '),
            );
            if ($break < 40) {
                $break = $max;
            }
            $chunks[] = substr($rest, 0, $break);
            $rest = ltrim(substr($rest, $break));
        }

        return $chunks;
    }

    private function myMemoryRequest(string $sourceLocale, string $targetLocale, string $text): string
    {
        $pair = strtolower(substr($sourceLocale, 0, 2)).'|'.strtolower(substr($targetLocale, 0, 2));
        $client = HttpClient::create(['timeout' => $this->timeout()]);
        try {
            $response = $client->request('GET', 'https://api.mymemory.translated.net/get', [
                'timeout' => $this->timeout(),
                'headers' => [
                    'User-Agent' => 'CPalius-CMF-Ai/1.0',
                ],
                'query' => [
                    'q' => $text,
                    'langpair' => $pair,
                ],
            ]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|HttpExceptionInterface $e) {
            $this->logger->warning('AI free translator request failed.', ['error' => $e->getMessage()]);
            throw new AiTranslationException('ai.error.unreachable', 0, $e);
        }

        if ($status >= 400) {
            $this->logger->warning('AI free translator HTTP error.', ['status' => $status]);
            throw new AiTranslationException('ai.error.unreachable', $status);
        }

        $out = trim((string) ($payload['responseData']['translatedText'] ?? ''));
        if ($out === '' || (int) ($payload['responseStatus'] ?? 0) >= 400 || str_contains(strtoupper($out), 'MYMEMORY WARNING')) {
            $this->logger->warning('AI free translator returned an empty string.');
            throw new AiTranslationException('ai.error.empty');
        }

        return $out;
    }

    private function parsePayload(string $raw): AiTranslationResult
    {
        $trimmed = trim($raw);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $data = json_decode($trimmed, true);
        if (!\is_array($data)) {
            throw new AiTranslationException('ai.error.invalid_json');
        }

        $title = trim((string) ($data['title'] ?? ''));
        $body = (string) ($data['body'] ?? '');
        if ($title === '' || trim($body) === '') {
            throw new AiTranslationException('ai.error.invalid_json');
        }

        return new AiTranslationResult(
            title: $title,
            body: $body,
            description: trim((string) ($data['description'] ?? '')),
            excerpt: trim((string) ($data['excerpt'] ?? '')),
        );
    }
}
