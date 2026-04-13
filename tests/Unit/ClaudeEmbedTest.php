<?php

use Dashed\DashedAi\Exceptions\EmbeddingNotSupportedException;
use Dashed\DashedClaude\ClaudeProvider;
use Tests\TestCase;

uses(TestCase::class);

it('throws EmbeddingNotSupportedException when embed() is called', function () {
    $provider = new ClaudeProvider;

    expect(fn () => $provider->embed('some text'))
        ->toThrow(EmbeddingNotSupportedException::class);
});
