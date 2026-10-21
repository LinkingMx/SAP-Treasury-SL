<?php

use App\Models\Acquirer;
use App\Models\User;
use App\Services\Ai\SettlementMappingAssistant;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * DeepSeek is reached through Laravel's HTTP client, so Http::fake() covers it.
 */
function fakeDeepSeekMapping(array|string $payload, int $status = 200): void
{
    config(['ai.driver' => 'deepseek']);

    Http::fake(['*/chat/completions' => $status === 200
        ? Http::response(['choices' => [[
            'message' => ['content' => is_string($payload) ? $payload : json_encode($payload)],
            'finish_reason' => 'stop',
        ]]])
        : Http::response(['error' => ['message' => 'Insufficient Balance']], $status)]);
}

/**
 * Note: Http::fake() does NOT intercept Gemini — its SDK ships its own Guzzle
 * client, so faking must go through the SDK's own facade.
 */
function fakeGemini(array $payload): void
{
    config(['ai.driver' => 'gemini']);

    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($payload)]]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function uberMappingAnswer(): array
{
    return [
        'columns' => [
            ['field' => 'transaction_date', 'index' => 16, 'confidence' => 100, 'why' => 'Fecha del pedido.'],
            ['field' => 'amount', 'index' => 15, 'confidence' => 100, 'why' => 'Es el valor del recibo.'],
        ],
        'warnings' => ['Hay columnas de tiempo que podrían confundirse con importes.'],
        'glossary' => [
            ['index' => 15, 'header' => 'Valor del recibo', 'meaning' => 'Importe cobrado al cliente.'],
        ],
    ];
}

/**
 * @return array<int, string>
 */
function uberHeaders(): array
{
    $headers = array_fill(0, 39, 'col');
    $headers[15] = 'Valor del recibo';
    $headers[16] = 'Fecha del pedido';
    $headers[27] = 'Tiempo total de entrega';

    return $headers;
}

it('maps the amount to the gross sale, not the delivery time', function (string $driver) {
    $driver === 'deepseek' ? fakeDeepSeekMapping(uberMappingAnswer()) : fakeGemini(uberMappingAnswer());

    $result = app(SettlementMappingAssistant::class)->suggest(uberHeaders(), [['a', 'b']], 'Uber Eats');

    expect($result)->not->toBeNull()
        ->and($result['columns']['amount']['index'])->toBe(15)
        ->and($result['columns']['amount']['why'])->toContain('recibo')
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['glossary'][0]['header'])->toBe('Valor del recibo');
})->with(['deepseek', 'gemini']);

it('sends the deterministic settings and the schema to DeepSeek', function () {
    fakeDeepSeekMapping(uberMappingAnswer());

    app(SettlementMappingAssistant::class)->suggest(uberHeaders(), [['a', 'b']], 'Uber Eats');

    Http::assertSent(fn (Request $request): bool => $request['temperature'] == 0
        && $request['response_format'] === ['type' => 'json_object']
        && str_contains($request['messages'][0]['content'], 'Uber Eats')
        && str_contains($request['messages'][0]['content'], '"transaction_date"'));
});

/**
 * DeepSeek cannot enforce the schema, so validation after the call is what
 * keeps an invented field or a phantom column out of the mapping.
 */
it('drops suggestions that point outside the file or at unknown fields', function () {
    fakeDeepSeekMapping([
        'columns' => [
            ['field' => 'amount', 'index' => 1, 'confidence' => 90, 'why' => 'ok'],
            ['field' => 'amount_neto', 'index' => 1, 'confidence' => 90, 'why' => 'campo inventado'],
            ['field' => 'reference', 'index' => 99, 'confidence' => 90, 'why' => 'columna inexistente'],
        ],
    ]);

    $result = app(SettlementMappingAssistant::class)->suggest(['Fecha', 'Monto'], [['01/01/2026', '10']]);

    expect($result['columns'])->toHaveKey('amount')
        ->and($result['columns'])->not->toHaveKey('amount_neto')
        ->and($result['columns'])->not->toHaveKey('reference');
});

it('returns null instead of throwing when the AI is unavailable', function (string $driver) {
    if ($driver === 'deepseek') {
        fakeDeepSeekMapping([], 402);
    } else {
        config(['ai.driver' => 'gemini']);
        Gemini::fake([new RuntimeException('Your project has been denied access.')]);
    }

    expect(app(SettlementMappingAssistant::class)->suggest(['Fecha', 'Monto'], [['01/01/2026', '10']]))
        ->toBeNull();
})->with(['deepseek', 'gemini']);

it('returns null when the model answers something that is not JSON', function () {
    fakeDeepSeekMapping('lo siento, no puedo');

    expect(app(SettlementMappingAssistant::class)->suggest(['Fecha'], [['x']]))->toBeNull();
});

it('never blocks the upload screen when the AI fails', function () {
    fakeDeepSeekMapping([], 402);

    $acquirer = Acquirer::factory()->create();
    $file = UploadedFile::fake()->createWithContent('m.csv', "Fecha,Monto\n01/04/2026,100\n");

    $this->actingAs(User::factory()->create())
        ->post(route('settlements.assist'), ['file' => $file, 'acquirer_id' => $acquirer->id])
        ->assertSuccessful()
        ->assertJsonPath('available', false);
});

it('exposes the AI suggestion through the assist endpoint', function () {
    fakeDeepSeekMapping([
        'columns' => [['field' => 'amount', 'index' => 1, 'confidence' => 95, 'why' => 'Importe de la venta.']],
        'warnings' => [],
        'glossary' => [],
    ]);

    $acquirer = Acquirer::factory()->create();
    $file = UploadedFile::fake()->createWithContent('m.csv', "Fecha,Monto\n01/04/2026,100\n");

    $this->actingAs(User::factory()->create())
        ->post(route('settlements.assist'), ['file' => $file, 'acquirer_id' => $acquirer->id])
        ->assertSuccessful()
        ->assertJsonPath('available', true)
        ->assertJsonPath('columns.amount.index', 1);
});
