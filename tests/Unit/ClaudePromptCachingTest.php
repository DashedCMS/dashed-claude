<?php

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Dashed\DashedClaude\ClaudeProvider;

uses(TestCase::class);

/**
 * Overrides only the DB-backed credential lookup so the payload-building
 * logic (the feature under test) runs for real without a migrated database.
 */
class CachingTestProvider extends ClaudeProvider
{
    public array $tracked = [];

    protected function apiKey(): ?string
    {
        return 'test-key';
    }

    protected function trackUsage(int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): void
    {
        $this->tracked[] = [$inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens];
    }
}

beforeEach(function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ], 200),
    ]);
});

it('adds cache_control to the system prompt and last tool when cache is enabled', function () {
    $provider = new CachingTestProvider();

    $provider->messages(
        [['role' => 'user', 'content' => 'Hallo']],
        [
            'system' => str_repeat('Je bent een behulpzame assistent. ', 5),
            'tools' => [
                ['name' => 'a', 'description' => 'tool a', 'input_schema' => ['type' => 'object']],
                ['name' => 'b', 'description' => 'tool b', 'input_schema' => ['type' => 'object']],
            ],
            'cache' => true,
        ]
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        // system is converted to a content-block array with cache_control on the last block
        expect($body['system'])->toBeArray();
        $lastSystem = end($body['system']);
        expect($lastSystem['type'])->toBe('text');
        expect($lastSystem['cache_control'])->toBe(['type' => 'ephemeral']);

        // cache_control sits on the last tool, not the first
        expect($body['tools'][0])->not->toHaveKey('cache_control');
        expect(end($body['tools'])['cache_control'])->toBe(['type' => 'ephemeral']);

        return true;
    });
});

it('leaves the payload untouched when cache is not enabled', function () {
    $provider = new CachingTestProvider();

    $provider->messages(
        [['role' => 'user', 'content' => 'Hallo']],
        [
            'system' => 'Je bent een behulpzame assistent.',
            'tools' => [
                ['name' => 'a', 'description' => 'tool a', 'input_schema' => ['type' => 'object']],
            ],
        ]
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($body['system'])->toBe('Je bent een behulpzame assistent.');
        expect($body['tools'][0])->not->toHaveKey('cache_control');
        expect($body)->not->toHaveKey('cache_control');

        return true;
    });
});

it('caches the system prompt in streamMessages when cache is enabled', function () {
    $provider = new CachingTestProvider();

    try {
        $provider->streamMessages(
            [['role' => 'user', 'content' => 'Hallo']],
            [
                'system' => str_repeat('Je bent een behulpzame assistent. ', 5),
                'cache' => true,
            ],
            fn () => null
        );
    } catch (\Throwable $e) {
        // The faked response has no streamable PSR body; we only assert the request payload.
    }

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($body['system'])->toBeArray();
        expect(end($body['system'])['cache_control'])->toBe(['type' => 'ephemeral']);

        return true;
    });
});

it('enables automatic caching for the conversation tail when cache is enabled', function () {
    $provider = new CachingTestProvider();

    $provider->messages(
        [['role' => 'user', 'content' => 'Hallo']],
        ['system' => 'Je bent een behulpzame assistent.', 'cache' => true]
    );

    Http::assertSent(function ($request) {
        expect($request->data()['cache_control'])->toBe(['type' => 'ephemeral']);

        return true;
    });
});

it('uses the one hour ttl on every breakpoint when cache is 1h', function () {
    $provider = new CachingTestProvider();

    $provider->messages(
        [['role' => 'user', 'content' => 'Hallo']],
        [
            'system' => 'Je bent een behulpzame assistent.',
            'tools' => [
                ['name' => 'a', 'description' => 'tool a', 'input_schema' => ['type' => 'object']],
            ],
            'cache' => '1h',
        ]
    );

    Http::assertSent(function ($request) {
        $body = $request->data();
        $oneHour = ['type' => 'ephemeral', 'ttl' => '1h'];

        expect($body['cache_control'])->toBe($oneHour);
        expect($body['system'][0]['cache_control'])->toBe($oneHour);
        expect($body['tools'][0]['cache_control'])->toBe($oneHour);

        return true;
    });
});

it('tracks cache reads and writes for messages', function () {
    // The stub from beforeEach would win otherwise: the first registered fake matches.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 2,
                'cache_read_input_tokens' => 3000,
                'cache_creation_input_tokens' => 150,
            ],
        ], 200),
    ]);

    $provider = new CachingTestProvider();
    $provider->messages([['role' => 'user', 'content' => 'Hallo']], ['cache' => true]);

    expect($provider->tracked)->toBe([[10, 2, 3000, 150]]);
});

it('returns and tracks cache reads and writes for streamMessages', function () {
    $sse = implode("\n\n", [
        'event: message_start' . "\n" . 'data: ' . json_encode(['type' => 'message_start', 'message' => ['usage' => [
            'input_tokens' => 10,
            'cache_read_input_tokens' => 3000,
            'cache_creation_input_tokens' => 150,
        ]]]),
        'event: content_block_delta' . "\n" . 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]),
        'event: message_delta' . "\n" . 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]]),
    ]) . "\n\n";

    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['api.anthropic.com/*' => Http::response($sse, 200)]);

    $provider = new CachingTestProvider();
    $result = $provider->streamMessages([['role' => 'user', 'content' => 'Hallo']], ['cache' => true], fn () => null);

    expect($result['usage'])->toBe([
        'input_tokens' => 10,
        'output_tokens' => 2,
        'cache_read_input_tokens' => 3000,
        'cache_creation_input_tokens' => 150,
    ]);
    expect($provider->tracked)->toBe([[10, 2, 3000, 150]]);
});
