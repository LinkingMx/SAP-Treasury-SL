<?php

use App\Services\Acquirer\SettlementParser;
use Illuminate\Http\UploadedFile;

function parserCsv(): UploadedFile
{
    $content = "Fecha,Autorizacion,Monto\n10/05/2026,A1,\$1100.00\n12/05/2026,A2,200.00\n";

    return UploadedFile::fake()->createWithContent('rappi.csv', $content);
}

it('reads headers and detects the header row + delimiter', function () {
    $read = (new SettlementParser)->readHeaders(parserCsv());

    expect($read['header_row'])->toBe(0)
        ->and($read['delimiter'])->toBe(',')
        ->and($read['rows'][0])->toBe(['Fecha', 'Autorizacion', 'Monto']);
});

it('suggests a mapping from header aliases', function () {
    $mapping = (new SettlementParser)->suggestMapping(['Fecha', 'Autorizacion', 'Monto']);

    expect($mapping['transaction_date'])->toBe(0)
        ->and($mapping['authorization'])->toBe(1)
        ->and($mapping['amount'])->toBe(2)
        ->and($mapping['reference'])->toBeNull();
});

it('prefers a saved mapping by header name', function () {
    $saved = ['columns' => ['amount' => ['header' => 'Monto'], 'transaction_date' => ['header' => 'Fecha']]];

    $mapping = (new SettlementParser)->suggestMapping(['Fecha', 'Autorizacion', 'Monto'], $saved);

    expect($mapping['amount'])->toBe(2)
        ->and($mapping['transaction_date'])->toBe(0);
});

it('parses rows with a manual column mapping (cleans amount, DD/MM date)', function () {
    $parseConfig = [
        'columns' => [
            'transaction_date' => ['index' => 0, 'format' => 'DD/MM/YYYY'],
            'authorization' => ['index' => 1],
            'amount' => ['index' => 2],
        ],
        'header_lines_count' => 1,
        'delimiter' => ',',
    ];

    $rows = (new SettlementParser)->parseRows(parserCsv(), $parseConfig);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['transaction_date'])->toBe('2026-05-10')
        ->and($rows[0]['amount'])->toBe(1100.0)
        ->and($rows[0]['authorization'])->toBe('A1')
        ->and($rows[1]['amount'])->toBe(200.0);
});

it('throws when date or amount is not mapped', function () {
    expect(fn () => (new SettlementParser)->parseRows(parserCsv(), ['columns' => ['amount' => ['index' => 2]]]))
        ->toThrow(RuntimeException::class);
});
