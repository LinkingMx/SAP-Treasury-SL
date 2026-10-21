<?php

use App\Services\Ai\AiErrorTranslator;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmFormat;
use App\Services\Ai\Llm\LlmOptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function deepSeekClient(): LlmClient
{
    config(['ai.driver' => 'deepseek', 'ai.deepseek.base_url' => 'https://api.deepseek.test']);

    return app(LlmClient::class);
}

/**
 * @return array<string, mixed>
 */
function deepSeekReply(string $content, string $finishReason = 'stop'): array
{
    return ['choices' => [[
        'message' => ['role' => 'assistant', 'content' => $content],
        'finish_reason' => $finishReason,
    ]]];
}

it('asks the configured model with thinking disabled, the key as bearer and JSON mode', function () {
    Http::fake(['*/chat/completions' => Http::response(deepSeekReply('{"ok":true}'))]);

    $response = deepSeekClient()->generate('Devuelve JSON', new LlmOptions(
        format: LlmFormat::JsonObject,
        maxTokens: 500,
        temperature: 0.0,
    ));

    expect($response->json())->toBe(['ok' => true])
        ->and($response->truncated)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.deepseek.test/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-test-not-a-real-key-000000')
        && $request['model'] === 'deepseek-flash'
        && $request['thinking'] === ['type' => 'disabled']
        && $request['response_format'] === ['type' => 'json_object']
        && $request['max_tokens'] === 500
        && $request['temperature'] == 0);
});

/**
 * DeepSeek's JSON mode rejects an array at the root, so array answers are
 * requested through the prompt only.
 */
it('does not use JSON mode when the caller expects an array', function () {
    Http::fake(['*/chat/completions' => Http::response(deepSeekReply('[{"seq":1}]'))]);

    $response = deepSeekClient()->generate('Clasifica', new LlmOptions(format: LlmFormat::JsonArray));

    expect($response->json())->toBe([['seq' => 1]]);
    Http::assertSent(fn (Request $request): bool => ! isset($request['response_format']));
});

it('sends the file inline and spells out the schema it cannot enforce', function () {
    Http::fake(['*/chat/completions' => Http::response(deepSeekReply('{}'))]);

    deepSeekClient()->generate('Analiza', new LlmOptions(
        format: LlmFormat::JsonObject,
        attachment: "Fecha,Monto\n01/04/2026,100",
        schema: ['type' => 'object', 'properties' => ['columns' => ['type' => 'array']]],
    ));

    Http::assertSent(function (Request $request): bool {
        $content = $request['messages'][0]['content'];

        return str_starts_with($content, 'Analiza')
            && str_contains($content, "ARCHIVO:\n```\nFecha,Monto\n01/04/2026,100\n```")
            && str_contains($content, 'JSON Schema')
            && str_contains($content, '"columns"');
    });
});

it('flags an answer cut off by the output limit', function () {
    Http::fake(['*/chat/completions' => Http::response(deepSeekReply('[{"sequence":1},{"seq', 'length'))]);

    $response = deepSeekClient()->generate('Extrae', new LlmOptions(format: LlmFormat::JsonArray));

    expect($response->truncated)->toBeTrue()
        ->and($response->json())->toBeNull();
});

it('reads JSON wrapped in a Markdown fence', function () {
    Http::fake(['*/chat/completions' => Http::response(deepSeekReply("```json\n{\"keywords\":\"CFE\"}\n```"))]);

    expect(deepSeekClient()->generate('Extrae')->json())->toBe(['keywords' => 'CFE']);
});

it('turns provider errors into a Spanish message without leaking the key', function (int $status, string $providerMessage, string $expected) {
    Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => $providerMessage]], $status)]);

    try {
        deepSeekClient()->generate('Hola');
        $this->fail('Expected the provider error to be thrown.');
    } catch (RuntimeException $e) {
        $message = AiErrorTranslator::translate($e);

        expect($message)->toContain($expected)
            ->and($message)->not->toContain('sk-test-not-a-real-key');
    }
})->with([
    'saldo agotado' => [402, 'Insufficient Balance', 'no está disponible'],
    'key invalida' => [401, 'Authentication Fails, Your api key: ****0000 is invalid', 'no es válida'],
    'limite' => [429, 'Rate limit reached for requests', 'cuota'],
    'saturado' => [503, 'Server overloaded, please try again later', 'saturado'],
]);

it('refuses to call the API without a key', function () {
    config(['ai.deepseek.api_key' => null]);
    Http::fake();

    try {
        deepSeekClient()->generate('Hola');
        $this->fail('Expected a missing-key error.');
    } catch (RuntimeException $e) {
        expect(AiErrorTranslator::translate($e))->toContain('no es válida');
    }

    Http::assertNothingSent();
});
