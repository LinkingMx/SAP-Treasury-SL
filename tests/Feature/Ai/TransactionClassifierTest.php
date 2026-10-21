<?php

use App\Models\LearningRule;
use App\Services\Ai\TransactionClassifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeClassifierAnswer(string $content): void
{
    config(['ai.driver' => 'deepseek']);

    Http::fake(['*/chat/completions' => Http::response(['choices' => [[
        'message' => ['content' => $content],
        'finish_reason' => 'stop',
    ]]])]);
}

/**
 * The classification answer is a JSON array, which DeepSeek's JSON mode
 * rejects, so it must be asked for without response_format and read even
 * when the model fences it.
 */
it('classifies with the learned rules through DeepSeek', function () {
    LearningRule::create([
        'pattern' => 'CFE SUMINISTRADOR DE SERVICIOS BASICOS',
        'sap_account_code' => '6010-001-000',
        'sap_account_name' => 'Energia electrica',
        'confidence_score' => 95,
    ]);
    fakeClassifierAnswer("```json\n[{\"seq\":1,\"sap\":\"6010-001-000\",\"conf\":92}]\n```");

    $result = app(TransactionClassifier::class)->classify([[
        'sequence' => 1,
        'due_date' => '2026-03-02',
        'memo' => 'PAGO LUZ RECIBO MARZO 99812',
        'debit_amount' => 1500.0,
        'credit_amount' => null,
    ]], [['code' => '6010-001-000', 'name' => 'Energia electrica']]);

    expect($result[0]['sap_account_code'])->toBe('6010-001-000')
        ->and($result[0]['confidence'])->toBe(92)
        ->and($result[0]['source'])->toBe('ai');

    Http::assertSent(fn (Request $request): bool => ! isset($request['response_format'])
        && str_contains($request['messages'][0]['content'], '[6010-001-000] CFE SUMINISTRADOR'));
});

/**
 * An unreadable answer must not invent an account: the chunk is left without
 * one, flagged as an AI error for the user to review.
 */
it('leaves the movement without an account when the answer is not JSON', function () {
    LearningRule::create([
        'pattern' => 'CFE SUMINISTRADOR DE SERVICIOS BASICOS',
        'sap_account_code' => '6010-001-000',
        'confidence_score' => 95,
    ]);
    fakeClassifierAnswer('No puedo clasificar esto.');

    $result = app(TransactionClassifier::class)->classify([[
        'sequence' => 1,
        'due_date' => '2026-03-02',
        'memo' => 'PAGO LUZ RECIBO MARZO 99812',
        'debit_amount' => 1500.0,
        'credit_amount' => null,
    ]], [['code' => '6010-001-000', 'name' => 'Energia electrica']]);

    expect($result[0]['source'])->toBe('error')
        ->and($result[0]['sap_account_code'])->toBeNull();

    Http::assertSentCount(1);
});

/**
 * Without a chart of accounts the AI is never asked; this is existing
 * behavior worth pinning so a provider change cannot silently alter it.
 */
it('uses the fallback account without calling the AI when there is no chart of accounts', function () {
    LearningRule::create([
        'pattern' => 'CFE SUMINISTRADOR DE SERVICIOS BASICOS',
        'sap_account_code' => '6010-001-000',
        'confidence_score' => 95,
    ]);
    fakeClassifierAnswer('[]');

    $result = app(TransactionClassifier::class)->classify([[
        'sequence' => 1,
        'due_date' => '2026-03-02',
        'memo' => 'PAGO LUZ RECIBO MARZO 99812',
        'debit_amount' => 1500.0,
        'credit_amount' => null,
    ]], []);

    expect($result[0]['source'])->toBe('fallback')
        ->and($result[0]['sap_account_code'])->toBe('1000-001-000');

    Http::assertNothingSent();
});

it('extracts the actor and RFC of a memo through DeepSeek JSON mode', function () {
    fakeClassifierAnswer('{"keywords": "comercializadora demo", "actor": "COMERCIALIZADORA DEMO", "rfc": "CDN1508224K1", "tipo": "ABONO"}');

    $pattern = app(TransactionClassifier::class)->extractCleanPattern(
        'SPEI RECIBIDO DE 40012-BBVA DE COMERCIALIZADORA DEMO SA DE CV RFC CDN1508224K1',
        creditAmount: 2500.0,
    );

    expect($pattern['keywords'])->toBe('COMERCIALIZADORA DEMO')
        ->and($pattern['actor'])->toBe('COMERCIALIZADORA DEMO')
        ->and($pattern['rfc'])->toBe('CDN1508224K1');

    Http::assertSent(fn (Request $request): bool => $request['response_format'] === ['type' => 'json_object']);
});
