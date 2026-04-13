<?php

namespace Dashed\DashedClaude;

use Exception;
use Dashed\DashedAi\AiProvider;
use Illuminate\Support\Facades\Http;
use Dashed\DashedAi\Enums\AiCapability;
use Filament\Forms\Components\TextInput;
use Dashed\DashedAi\Exceptions\AiException;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedAi\Exceptions\AiRateLimitException;

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

        return cache()->rememberForever('ai_connected_claude', function () use ($apiKey) {
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

            return $response->successful() || $response->status() === 429;
        });
    }

    public function text(string $prompt, array $options = []): ?string
    {
        return $this->request($prompt, $options);
    }

    public function json(string $prompt, array $options = []): ?array
    {
        $text = $this->request($prompt, $options);

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
            'model' => self::MODEL,
            'max_tokens' => $options['max_tokens'] ?? 200,
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

        $system = $this->buildSystemPrompt($options);
        if ($system) {
            $body['system'] = $system;
        }

        try {
            $response = Http::withHeaders($this->headers($apiKey))
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', $body);
        } catch (Exception) {
            return null;
        }

        if ($response->successful()) {
            $this->trackUsage(
                $response->json('usage.input_tokens', 0),
                $response->json('usage.output_tokens', 0),
            );

            return $response->json('content.0.text');
        }

        if ($response->status() === 429) {
            throw new AiRateLimitException('Claude rate limit exceeded');
        }

        return null;
    }

    public function image(string $prompt, array $options = []): ?string
    {
        return null;
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('claude_api_key')
                ->label('Claude API sleutel')
                ->password()
                ->revealable()
                ->placeholder('sk-ant-...')
                ->helperText('Je vindt je API sleutel op console.anthropic.com → API Keys.'),
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

    protected function request(string $prompt, array $options = []): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return null;
        }

        $body = [
            'model' => self::MODEL,
            'max_tokens' => $options['max_tokens'] ?? 4000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        $system = $this->buildSystemPrompt($options);
        if ($system) {
            $body['system'] = $system;
        }

        $response = Http::withHeaders($this->headers($apiKey))
            ->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', $body);

        if ($response->successful()) {
            $this->trackUsage(
                $response->json('usage.input_tokens', 0),
                $response->json('usage.output_tokens', 0),
            );

            return $response->json('content.0.text');
        }

        $errorMessage = $response->json('error.message') ?? $response->body();
        $errorType = $response->json('error.type') ?? 'http_' . $response->status();

        if ($response->status() === 429 || $errorType === 'rate_limit_error') {
            throw new AiRateLimitException("[{$errorType}] {$errorMessage}");
        }

        throw new AiException("[{$errorType}] {$errorMessage}");
    }

    protected function trackUsage(int $inputTokens, int $outputTokens): void
    {
        $dayKey = 'ai_usage_claude_day_' . now()->format('Y_m_d');
        $day = Customsetting::get($dayKey, null, []) ?: [];
        $day['input_tokens'] = ($day['input_tokens'] ?? 0) + $inputTokens;
        $day['output_tokens'] = ($day['output_tokens'] ?? 0) + $outputTokens;
        $day['calls'] = ($day['calls'] ?? 0) + 1;
        Customsetting::set($dayKey, $day);

        $monthKey = 'ai_usage_claude_' . now()->format('Y_m');
        $month = Customsetting::get($monthKey, null, []) ?: [];
        $month['input_tokens'] = ($month['input_tokens'] ?? 0) + $inputTokens;
        $month['output_tokens'] = ($month['output_tokens'] ?? 0) + $outputTokens;
        $month['calls'] = ($month['calls'] ?? 0) + 1;
        Customsetting::set($monthKey, $month);
    }
}
