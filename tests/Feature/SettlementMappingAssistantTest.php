<?php

use App\Models\Acquirer;
use App\Models\User;
use App\Services\Ai\SettlementMappingAssistant;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use Illuminate\Http\UploadedFile;

/**
 * Note: Http::fake() does NOT intercept these calls — the Gemini SDK ships its
 * own Guzzle client, so faking must go through the SDK's own facade.
 */
function fakeGemini(array $payload): void
{
    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($payload)]]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);
}

it('maps the amount to the gross sale, not the delivery time', function () {
    fakeGemini([
        'columns' => [
            ['field' => 'transaction_date', 'index' => 16, 'confidence' => 100, 'why' => 'Fecha del pedido.'],
            ['field' => 'amount', 'index' => 15, 'confidence' => 100, 'why' => 'Es el valor del recibo.'],
        ],
        'warnings' => ['Hay columnas de tiempo que podrían confundirse con importes.'],
        'glossary' => [
            ['index' => 15, 'header' => 'Valor del recibo', 'meaning' => 'Importe cobrado al cliente.'],
        ],
    ]);

    $headers = array_fill(0, 39, 'col');
    $headers[15] = 'Valor del recibo';
    $headers[16] = 'Fecha del pedido';
    $headers[27] = 'Tiempo total de entrega';

    $result = (new SettlementMappingAssistant)->suggest($headers, [['a', 'b']], 'Uber Eats');

    expect($result)->not->toBeNull()
        ->and($result['columns']['amount']['index'])->toBe(15)
        ->and($result['columns']['amount']['why'])->toContain('recibo')
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['glossary'][0]['header'])->toBe('Valor del recibo');
});

it('drops suggestions that point outside the file or at unknown fields', function () {
    fakeGemini([
        'columns' => [
            ['field' => 'amount', 'index' => 1, 'confidence' => 90, 'why' => 'ok'],
            ['field' => 'amount_neto', 'index' => 1, 'confidence' => 90, 'why' => 'campo inventado'],
            ['field' => 'reference', 'index' => 99, 'confidence' => 90, 'why' => 'columna inexistente'],
        ],
    ]);

    $result = (new SettlementMappingAssistant)->suggest(['Fecha', 'Monto'], [['01/01/2026', '10']]);

    expect($result['columns'])->toHaveKey('amount')
        ->and($result['columns'])->not->toHaveKey('amount_neto')
        ->and($result['columns'])->not->toHaveKey('reference');
});

it('returns null instead of throwing when the AI is unavailable', function () {
    Gemini::fake([new RuntimeException('Your project has been denied access.')]);

    expect((new SettlementMappingAssistant)->suggest(['Fecha', 'Monto'], [['01/01/2026', '10']]))
        ->toBeNull();
});

it('returns null when the model answers something that is not JSON', function () {
    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'lo siento, no puedo']]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);

    expect((new SettlementMappingAssistant)->suggest(['Fecha'], [['x']]))->toBeNull();
});

it('never blocks the upload screen when the AI fails', function () {
    Gemini::fake([new RuntimeException('down')]);

    $acquirer = Acquirer::factory()->create();
    $file = UploadedFile::fake()->createWithContent('m.csv', "Fecha,Monto\n01/04/2026,100\n");

    $this->actingAs(User::factory()->create())
        ->post(route('settlements.assist'), ['file' => $file, 'acquirer_id' => $acquirer->id])
        ->assertSuccessful()
        ->assertJsonPath('available', false);
});

it('exposes the AI suggestion through the assist endpoint', function () {
    fakeGemini([
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
