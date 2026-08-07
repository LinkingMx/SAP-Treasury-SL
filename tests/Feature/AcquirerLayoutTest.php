<?php

use App\Models\Acquirer;
use App\Models\AcquirerLayout;

it('fingerprints by header names, ignoring the data below them', function () {
    $headers = ['Fecha transacción', 'Monto', 'Núm. de autorización'];

    // Same headers, different month: must be the same layout.
    expect(AcquirerLayout::fingerprintFor($headers))
        ->toBe(AcquirerLayout::fingerprintFor($headers));

    // A different column set is a different layout.
    expect(AcquirerLayout::fingerprintFor($headers))
        ->not->toBe(AcquirerLayout::fingerprintFor([...$headers, 'Estatus']));
});

it('ignores header order, casing, accents and blank columns', function () {
    expect(AcquirerLayout::fingerprintFor(['Fecha transacción', 'Monto', '']))
        ->toBe(AcquirerLayout::fingerprintFor(['MONTO', 'fecha transaccion']));
});

it('lets one acquirer keep several layouts (xlsx and csv)', function () {
    $acquirer = Acquirer::factory()->create(['code' => 'MIFEL', 'name' => 'CC MIFEL']);

    $xlsx = AcquirerLayout::fingerprintFor(['Fecha transacción', 'Monto']);
    $csv = AcquirerLayout::fingerprintFor(['Fecha de aplicación', 'Fecha transacción', 'Monto', 'Estatus']);

    AcquirerLayout::create([
        'acquirer_id' => $acquirer->id,
        'fingerprint' => $xlsx,
        'column_map' => ['columns' => ['amount' => ['header' => 'Monto']]],
        'delimiter' => "\t",
        'header_row' => 0,
    ]);
    AcquirerLayout::create([
        'acquirer_id' => $acquirer->id,
        'fingerprint' => $csv,
        'column_map' => ['columns' => ['amount' => ['header' => 'Monto']]],
        'delimiter' => ',',
        'header_row' => 7,
    ]);

    expect(AcquirerLayout::where('acquirer_id', $acquirer->id)->count())->toBe(2)
        ->and(AcquirerLayout::findFor($acquirer->id, $csv)->header_row)->toBe(7)
        ->and(AcquirerLayout::findFor($acquirer->id, $xlsx)->delimiter)->toBe("\t");
});

it('counts how often a learned layout spared us the work', function () {
    $acquirer = Acquirer::factory()->create();
    $layout = AcquirerLayout::create([
        'acquirer_id' => $acquirer->id,
        'fingerprint' => AcquirerLayout::fingerprintFor(['Fecha', 'Monto']),
        'column_map' => ['columns' => []],
    ]);

    expect($layout->fresh()->times_used)->toBe(0);

    $layout->markUsed();
    $layout->markUsed();

    expect($layout->fresh()->times_used)->toBe(2)
        ->and($layout->fresh()->last_used_at)->not->toBeNull();
});

it('returns null for an acquirer that has never sent this shape', function () {
    $acquirer = Acquirer::factory()->create();

    expect(AcquirerLayout::findFor($acquirer->id, AcquirerLayout::fingerprintFor(['Cualquier cosa'])))
        ->toBeNull();
});
