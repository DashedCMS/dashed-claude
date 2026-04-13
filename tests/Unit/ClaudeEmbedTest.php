<?php

use Tests\TestCase;
use Dashed\DashedClaude\ClaudeProvider;
use Dashed\DashedAi\Exceptions\EmbeddingNotSupportedException;

uses(TestCase::class);

it('throws EmbeddingNotSupportedException when embed() is called', function () {
    $provider = new ClaudeProvider();

    expect(fn () => $provider->embed('some text'))
        ->toThrow(EmbeddingNotSupportedException::class);
});
