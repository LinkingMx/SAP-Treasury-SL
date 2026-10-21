<?php

use App\Services\Ai\BankLayoutAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function santanderStatement(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('santander.csv', implode("\n", [
        'BANCO SANTANDER MEXICO',
        'Cuenta: 65-50123456-7',
        'Periodo: 01/02/2026 al 28/02/2026',
        '',
        "Fecha\tDescripcion\tCargo\tAbono\tSaldo",
        "17/02/26\tTRASPASO A TERCEROS\t1500.00\t\t98500.00",
        "18/02/26\tDEPOSITO EFECTIVO\t\t3000.00\t101500.00",
        "19/02/26\tCOMISION MANEJO\t150.00\t\t101350.00",
    ]));
}

/**
 * @return array<string, mixed>
 */
function santanderConfig(int $headerLines): array
{
    return [
        'header_lines_count' => $headerLines,
        'delimiter' => "\t",
        'amount_style' => 'separate',
        'columns' => [
            'date' => ['index' => 0, 'format' => 'DD/MM/YY'],
            'memo' => ['index' => 1],
            'debit' => ['index' => 2],
            'credit' => ['index' => 3],
        ],
    ];
}

function fakeDeepSeekAnswer(string $content, string $finishReason = 'stop'): void
{
    config(['ai.driver' => 'deepseek']);

    Http::fake(['*/chat/completions' => Http::response(['choices' => [[
        'message' => ['content' => $content],
        'finish_reason' => $finishReason,
    ]]])]);
}

/**
 * DeepSeek consistently reports one header line fewer than there are when the
 * statement has a preamble. That must cost nothing: the column-title row it
 * leaves in has no valid date and is skipped. Counting one MORE would drop a
 * real movement, which is why this test pins both directions.
 */
it('keeps every movement when the header count comes back one short', function (int $headerLines) {
    Http::fake();

    $transactions = app(BankLayoutAnalyzer::class)->parseTransactions(santanderStatement(), santanderConfig($headerLines));

    expect($transactions)->toHaveCount(3)
        ->and($transactions[0]['memo'])->toBe('TRASPASO A TERCEROS')
        ->and($transactions[0]['debit_amount'])->toBe(1500.0)
        ->and($transactions[1]['credit_amount'])->toBe(3000.0);

    Http::assertNothingSent();
})->with(['exact count' => 5, 'one short, as DeepSeek answers' => 4]);

it('loses the first movement if the header count is one too many', function () {
    $transactions = app(BankLayoutAnalyzer::class)->parseTransactions(santanderStatement(), santanderConfig(6));

    expect($transactions)->toHaveCount(2);
});

it('analyzes the layout with DeepSeek, sending the statement inline', function () {
    fakeDeepSeekAnswer(json_encode([
        'bank_name_guess' => 'Santander',
        'header_lines_count' => 4,
        'delimiter' => '\t',
        'amount_style' => 'separate',
        'columns' => santanderConfig(4)['columns'],
    ]));

    $result = app(BankLayoutAnalyzer::class)->analyze(santanderStatement());

    expect($result['bank_name_guess'])->toBe('Santander')
        ->and($result['parse_config']['amount_style'])->toBe('separate');

    Http::assertSent(fn (Request $request): bool => $request['response_format'] === ['type' => 'json_object']
        && str_contains($request['messages'][0]['content'], "ARCHIVO:\n```\nBANCO SANTANDER MEXICO"));
});

it('repairs a chunk the model cut off at its output limit', function () {
    fakeDeepSeekAnswer(
        '[{"sequence":1,"due_date":"2026-02-17","memo":"TRASPASO","debit_amount":1500,"credit_amount":null},{"sequence":2,"due_da',
        'length',
    );

    $statement = UploadedFile::fake()->createWithContent('raro.txt', "17/02/26 TRASPASO 1500.00\n18/02/26 DEPOSITO 3000.00\n");
    $transactions = app(BankLayoutAnalyzer::class)->parseTransactions($statement, ['header_lines_count' => 0]);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]['memo'])->toBe('TRASPASO');
});
