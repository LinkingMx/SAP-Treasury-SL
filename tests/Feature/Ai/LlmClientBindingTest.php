<?php

use App\Services\Ai\Llm\DeepSeekLlmClient;
use App\Services\Ai\Llm\GeminiLlmClient;
use App\Services\Ai\Llm\LlmClient;

/**
 * The provider is an environment switch so production can leave a failing
 * provider without shipping a release.
 */
it('resolves the provider named in AI_DRIVER', function (string $driver, string $expected) {
    config(['ai.driver' => $driver]);

    expect(app(LlmClient::class))->toBeInstanceOf($expected);
})->with([
    'deepseek' => ['deepseek', DeepSeekLlmClient::class],
    'gemini' => ['gemini', GeminiLlmClient::class],
]);

it('fails loudly on a misspelled driver instead of silently picking one', function () {
    config(['ai.driver' => 'deepseeek']);

    app(LlmClient::class);
})->throws(InvalidArgumentException::class, 'AI_DRIVER "deepseeek" no es válido');
