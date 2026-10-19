<?php

namespace Dashed\DashedClaude;

use Exception;
use Dashed\DashedAi\AiProvider;
use Illuminate\Support\Facades\Http;
use Dashed\DashedAi\Logging\AiLogger;
use Dashed\DashedAi\Enums\AiCapability;
use Filament\Forms\Components\TextInput;
use Dashed\DashedAi\Exceptions\AiException;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedAi\Exceptions\AiRateLimitException;
use Dashed\DashedAi\Exceptions\EmbeddingNotSupportedException;

class ClaudeProvider extends AiProvider
{
    protected const MODEL = 'claude-sonnet-4-6';

    protected const ANTHROPIC_VERSION = '2023-06-01';

    public function name(): string
    {
        return 'claude';
    }

    public function label(): string
    {
        return 'Claude (Anthropic)';
    }

    public function supportedCapabilities(): array
    {
        return [
            AiCapability::Text,
            AiCapability::Json,
            AiCapability::Vision,
        ];
    }

    public function isConnected(): bool
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return false;
        }

        // Een positieve verbinding cachen we lang; een NEGATIEVE nooit blijvend.
        // Anders zet één transiënte hik (time-out/5xx) 'niet verbonden' voor
        // altijd vast en ligt de hele AI plat tot een handmatige cache:clear.
        if (cache()->get('ai_connected_claude') === true) {
            return true;
        }

        try {
            $response = Http::withHeaders($this->headers($apiKey))
                ->timeout(10)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                ]);
        } catch (Exception) {
            return false;
        }

        $connected = $response->successful() || $response->status() === 429;

        // true → forever; false → korte TTL zodat het vanzelf herstelt zonder
        // bij elke call opnieuw de API te bevragen.
        cache()->put('ai_connected_claude', $connected, $connected ? null : now()->addSeconds(60));

        return $connected;
    }

    public function text(string $prompt, array $options = []): ?string
    {
        return $this->request($prompt, $options);
    }

    public function json(string $prompt, array $options = []): ?array
    {
        $text = $this->request($prompt, $options + ['log_kind' => 'json']);

        if (! $text) {
            return null;
        }

        $result = $this->parseJsonResponse($text);

        if ($result === null) {
            throw new AiException('Claude gaf geen geldig JSON terug. Antwoord: ' . $text);
        }

        return $result;
    }

    public function vision(string $prompt, string $imageData, string $mimeType, array $options = []): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return null;
        }

        $body = [
            'model' => $options['model'] ?? self::MODEL,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $imageData,
                        ],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        $system = $this->buildSystemPrompt($options);
        if ($system) {
            $body['system'] = $system;
        }

        $log = AiLogger::begin('claude', 'vision', ['model' => $body['model'], 'prompt' => $body, 'options' => $options]);

        try {
            $response = Http::withHeaders($this->headers($apiKey))
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', $body);
        } catch (Exception $e) {
            $log->fail($e);

            return null;
        }

        if ($response->successful()) {
            $this->trackUsage(
                $response->json('usage.input_tokens', 0),
                $response->json('usage.output_tokens', 0),
            );
            $log->model($response->json('model'))->usage($response->json('usage'))->response($response->json('content.0.text'))->succeed();

            return $response->json('content.0.text');
        }

        $log->fail('HTTP ' . $response->status() . ' ' . ($response->json('error.message') ?? ''));

        if ($response->status() === 429) {
            throw new AiRateLimitException('Claude rate limit exceeded');
        }

        return null;
    }

    public function messages(array $messages, array $options = []): array
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            throw new AiException('Geen Claude API key ingesteld.');
        }

        $payload = [
            'model' => $options['model'] ?? static::MODEL,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => $messages,
        ];
        if (! empty($options['system'])) {
            $payload['system'] = $options['system'];
        }
        if (! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        if (! empty($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (! empty($options['cache'])) {
            $payload = $this->applyCacheControl($payload, $options['cache']);
        }

        $log = AiLogger::begin('claude', 'messages', ['model' => $payload['model'], 'prompt' => $this->promptForLog($payload), 'options' => $options]);

        try {
            $response = Http::withHeaders($this->headers($apiKey))
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', $payload);
        } catch (Exception $e) {
            $log->fail($e);

            throw $e;
        }

        if ($response->status() === 429) {
            $log->fail('HTTP 429 rate limit');

            throw new AiRateLimitException('Claude rate limit.');
        }
        if (! $response->successful()) {
            $log->fail('HTTP ' . $response->status() . ' ' . ($response->json('error.message') ?? $response->body()));

            throw new AiException('Claude fout: ' . $response->status() . ' ' . $response->body());
        }

        $this->trackUsage(
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            (int) $response->json('usage.cache_read_input_tokens', 0),
            (int) $response->json('usage.cache_creation_input_tokens', 0),
        );
        $log->model($response->json('model'))->usage($response->json('usage'))->response($response->json('content'))->succeed();

        return $response->json();
    }

    public function streamMessages(array $messages, array $options, callable $onText): array
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            throw new AiException('Geen Claude API key.');
        }

        $payload = array_filter([
            'model' => $options['model'] ?? static::MODEL,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => $messages,
            'system' => $options['system'] ?? null,
            'tools' => $options['tools'] ?? null,
            'tool_choice' => $options['tool_choice'] ?? null,
            'temperature' => $options['temperature'] ?? null,
            'stream' => true,
        ], fn ($v) => $v !== null);

        if (! empty($options['cache'])) {
            $payload = $this->applyCacheControl($payload, $options['cache']);
        }

        $log = AiLogger::begin('claude', 'stream', ['model' => $payload['model'], 'prompt' => $this->promptForLog($payload), 'options' => $options]);

        try {
            $response = Http::withOptions(['stream' => true])
                ->withHeaders($this->headers($apiKey))
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', $payload);
        } catch (Exception $e) {
            $log->fail($e);

            throw $e;
        }

        if ($response->status() === 429) {
            $log->fail('HTTP 429 rate limit');

            throw new AiRateLimitException('Claude stream rate limit.');
        }
        if (! $response->successful()) {
            $log->fail('HTTP ' . $response->status());

            throw new AiException('Claude stream fout: ' . $response->status());
        }

        $state = [
            'text' => '',
            'stopReason' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'tools' => [],
        ];

        $leftover = '';

        try {
            $body = $response->toPsrResponse()->getBody();
            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '' || $chunk === false) {
                    continue;
                }
                $leftover .= $chunk;
                $events = explode("\n\n", $leftover);
                // The last element may be incomplete; keep it as leftover.
                $leftover = array_pop($events);
                foreach ($events as $event) {
                    $this->parseSseEvent($event, $state, $onText);
                }
            }
            // Process any remaining data after EOF.
            if ($leftover !== '') {
                foreach (explode("\n\n", $leftover) as $event) {
                    $this->parseSseEvent($event, $state, $onText);
                }
            }
        } catch (\Throwable $e) {
            // If PSR streaming is not available (e.g. Http::fake in tests),
            // fall back to parsing the full body at once.
            $fallback = $response->body();
            $state = [
                'text' => '',
                'stopReason' => null,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'tools' => [],
            ];
            foreach (explode("\n\n", $fallback) as $event) {
                $this->parseSseEvent($event, $state, $onText);
            }
        }

        $content = [];
        if ($state['text'] !== '') {
            $content[] = ['type' => 'text', 'text' => $state['text']];
        }
        foreach ($state['tools'] as $t) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $t['id'],
                'name' => $t['name'],
                'input' => json_decode($t['input_json'] ?: '{}', true) ?: [],
            ];
        }

        $result = [
            'stop_reason' => $state['stopReason'],
            'content' => $content,
            'usage' => $state['usage'],
        ];

        $this->trackUsage(
            $result['usage']['input_tokens'] ?? 0,
            $result['usage']['output_tokens'] ?? 0,
            $result['usage']['cache_read_input_tokens'] ?? 0,
            $result['usage']['cache_creation_input_tokens'] ?? 0,
        );
        $log->usage($result['usage'])->response($content)->succeed();

        return $result;
    }

    /**
     * Parse a single SSE event block and mutate $state accordingly.
     *
     * @param array{text:string,stopReason:?string,usage:array,tools:array} $state
     */
    protected function parseSseEvent(string $event, array &$state, callable $onText): void
    {
        if (! str_contains($event, 'data:')) {
            return;
        }
        $json = trim(substr($event, strpos($event, 'data:') + 5));
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return;
        }

        switch ($data['type'] ?? null) {
            case 'message_start':
                $state['usage']['input_tokens'] = $data['message']['usage']['input_tokens'] ?? 0;
                foreach (['cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
                    if (isset($data['message']['usage'][$key])) {
                        $state['usage'][$key] = (int) $data['message']['usage'][$key];
                    }
                }

                break;
            case 'content_block_start':
                if (($data['content_block']['type'] ?? null) === 'tool_use') {
                    $state['tools'][$data['index']] = [
                        'type' => 'tool_use',
                        'id' => $data['content_block']['id'],
                        'name' => $data['content_block']['name'],
                        'input_json' => '',
                    ];
                }

                break;
            case 'content_block_delta':
                $delta = $data['delta'] ?? [];
                if (($delta['type'] ?? null) === 'text_delta') {
                    $state['text'] .= $delta['text'];
                    $onText($delta['text']);
                } elseif (($delta['type'] ?? null) === 'input_json_delta' && isset($state['tools'][$data['index']])) {
                    $state['tools'][$data['index']]['input_json'] .= $delta['partial_json'] ?? '';
                }

                break;
            case 'message_delta':
                $state['stopReason'] = $data['delta']['stop_reason'] ?? $state['stopReason'];
                $state['usage']['output_tokens'] = $data['usage']['output_tokens'] ?? $state['usage']['output_tokens'];

                break;
        }
    }

    /**
     * @deprecated Use parseSseEvent with a $state array instead.
     */
    protected function parseSse(string $body, callable $onText): array
    {
        $state = [
            'text' => '',
            'stopReason' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'tools' => [],
        ];

        foreach (explode("\n\n", $body) as $event) {
            $this->parseSseEvent($event, $state, $onText);
        }

        $content = [];
        if ($state['text'] !== '') {
            $content[] = ['type' => 'text', 'text' => $state['text']];
        }
        foreach ($state['tools'] as $t) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $t['id'],
                'name' => $t['name'],
                'input' => json_decode($t['input_json'] ?: '{}', true) ?: [],
            ];
        }

        return ['stop_reason' => $state['stopReason'], 'content' => $content, 'usage' => $state['usage']];
    }

    public function image(string $prompt, array $options = []): ?string
    {
        return null;
    }

    public function embed(string $text, array $options = []): array
    {
        throw EmbeddingNotSupportedException::forProvider('claude');
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('claude_api_key')
                ->label(__('Claude API sleutel'))
                ->password()
                ->revealable()
                ->placeholder(__('sk-ant-...'))
                ->helperText(__('Je vindt je API sleutel op console.anthropic.com → API Keys.')),
        ];
    }

    protected function apiKey(): ?string
    {
        return Customsetting::get('claude_api_key');
    }

    protected function headers(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Zet prompt-caching breakpoints op de payload.
     *
     * De prefix-volgorde bij Anthropic is tools -> system -> messages. Er
     * komen twee soorten breakpoints:
     *
     * 1. Expliciet op het laatste system-blok en de laatste tool-definitie:
     *    de vaste prefix houdt zo een gegarandeerd leespunt, wat er verderop
     *    in het gesprek ook gebeurt. Een string system-prompt wordt naar het
     *    content-block formaat omgezet, want cache_control kan alleen op een
     *    blok staan.
     * 2. Top-level cache_control (automatische caching): Anthropic zet het
     *    breakpoint op het laatste cachebare blok en schuift het mee met het
     *    gesprek. In een agent-loop leest elke volgende beurt daardoor ook de
     *    eerdere berichten en tool-resultaten uit de cache.
     *
     * $cache is true (5 minuten) of '1h'. Neem '1h' alleen als er tussen twee
     * calls met dezelfde prefix vaak meer dan 5 minuten zit: schrijven kost
     * dan 2x de inputprijs in plaats van 1,25x. Alle breakpoints krijgen
     * dezelfde TTL, want een langere TTL mag niet na een kortere komen.
     *
     * Let op: alleen inschakelen voor herhaalde calls met een stabiele prefix
     * (zoals de livechat-agent). Bij one-shot calls betaal je alleen de
     * schrijfpremie. Een prefix onder het minimum van het model (512 tot 4096
     * tokens) cachet stilzwijgend niet; controleer met
     * usage.cache_read_input_tokens.
     */
    protected function applyCacheControl(array $payload, bool|string $cache = true): array
    {
        $cacheControl = ['type' => 'ephemeral'];
        if ($cache === '1h') {
            $cacheControl['ttl'] = '1h';
        }

        if (! empty($payload['system'])) {
            if (is_string($payload['system'])) {
                $payload['system'] = [[
                    'type' => 'text',
                    'text' => $payload['system'],
                    'cache_control' => $cacheControl,
                ]];
            } elseif (is_array($payload['system'])) {
                $lastKey = array_key_last($payload['system']);
                if ($lastKey !== null && is_array($payload['system'][$lastKey])) {
                    $payload['system'][$lastKey]['cache_control'] = $cacheControl;
                }
            }
        }

        if (! empty($payload['tools']) && is_array($payload['tools'])) {
            $lastKey = array_key_last($payload['tools']);
            if ($lastKey !== null && is_array($payload['tools'][$lastKey])) {
                $payload['tools'][$lastKey]['cache_control'] = $cacheControl;
            }
        }

        $payload['cache_control'] = $cacheControl;

        return $payload;
    }

    protected function request(string $prompt, array $options = []): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return null;
        }

        $body = [
            'model' => $options['model'] ?? self::MODEL,
            'max_tokens' => $options['max_tokens'] ?? 4000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        $system = $this->buildSystemPrompt($options);
        if ($system) {
            $body['system'] = $system;
        }

        $log = AiLogger::begin('claude', $options['log_kind'] ?? 'text', ['model' => $body['model'], 'prompt' => $this->promptForLog($body), 'options' => $options]);

        try {
            $response = Http::withHeaders($this->headers($apiKey))
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', $body);
        } catch (Exception $e) {
            $log->fail($e);

            throw $e;
        }

        if ($response->successful()) {
            $this->trackUsage(
                $response->json('usage.input_tokens', 0),
                $response->json('usage.output_tokens', 0),
            );
            $log->model($response->json('model'))->usage($response->json('usage'))->response($response->json('content.0.text'))->succeed();

            return $response->json('content.0.text');
        }

        $errorMessage = $response->json('error.message') ?? $response->body();
        $errorType = $response->json('error.type') ?? 'http_' . $response->status();
        $log->fail("[{$errorType}] {$errorMessage}");

        if ($response->status() === 429 || $errorType === 'rate_limit_error') {
            throw new AiRateLimitException("[{$errorType}] {$errorMessage}");
        }

        throw new AiException("[{$errorType}] {$errorMessage}");
    }

    /**
     * Wat er in het logboek komt als prompt: system-prompt en berichten, zonder
     * tool-definities (die staan al als namen in de opties).
     */
    protected function promptForLog(array $payload): array
    {
        return array_filter([
            'system' => $payload['system'] ?? null,
            'messages' => $payload['messages'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * input_tokens telt alleen de niet-gecachete tokens; wat uit de cache
     * kwam of ernaartoe geschreven is staat in de twee cache-tellers.
     */
    protected function trackUsage(int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): void
    {
        $keys = [
            'ai_usage_claude_day_' . now()->format('Y_m_d'),
            'ai_usage_claude_' . now()->format('Y_m'),
        ];

        foreach ($keys as $key) {
            $usage = Customsetting::get($key, null, []) ?: [];
            $usage['input_tokens'] = ($usage['input_tokens'] ?? 0) + $inputTokens;
            $usage['output_tokens'] = ($usage['output_tokens'] ?? 0) + $outputTokens;
            $usage['cache_read_input_tokens'] = ($usage['cache_read_input_tokens'] ?? 0) + $cacheReadTokens;
            $usage['cache_creation_input_tokens'] = ($usage['cache_creation_input_tokens'] ?? 0) + $cacheWriteTokens;
            $usage['calls'] = ($usage['calls'] ?? 0) + 1;
            Customsetting::set($key, $usage);
        }
    }
}
