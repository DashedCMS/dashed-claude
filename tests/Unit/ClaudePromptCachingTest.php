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
    protected function apiKey(): ?string
    {
        return 'test-key';
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
