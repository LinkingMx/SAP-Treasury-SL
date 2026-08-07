<?php

use App\Services\Acquirer\MappingGuards;

/**
 * These are the exact mistakes the alias matcher makes on the real Uber and
 * Rappi exports. The guards must catch them without any AI, since the AI is
 * allowed to be unavailable.
 */
it('warns when the amount column is actually a duration (Uber)', function () {
    $warnings = (new MappingGuards)->check(
        ['amount' => ['index' => 27, 'header' => 'Tiempo total de entrega']],
        [['amount' => 30.63], ['amount' => 11.16]],
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['field'])->toBe('amount')
        ->and($warnings[0]['message'])->toContain('tiempo');
});

it('warns when the amount column is a commission or discount (MIFEL)', function () {
    $warnings = (new MappingGuards)->check(
        ['amount' => ['index' => 11, 'header' => 'Monto de descuento']],
        [['amount' => 70.33]],
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toContain('descuento');
});

it('stays quiet on a correct amount mapping', function () {
    $warnings = (new MappingGuards)->check(
        ['amount' => ['index' => 15, 'header' => 'Valor del recibo']],
        [['amount' => 514.0], ['amount' => 475.0]],
    );

    expect($warnings)->toBe([]);
});

it('warns when the reference is really a date column (Rappi)', function () {
    $warnings = (new MappingGuards)->check(
        [
            'amount' => ['index' => 17, 'header' => 'Venta Bruta'],
            'reference' => ['index' => 0, 'header' => 'Fecha de creación orden'],
        ],
        [
            ['amount' => 2102.0, 'reference' => '01/04/2026'],
            ['amount' => 500.0, 'reference' => '02/04/2026'],
        ],
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['field'])->toBe('reference');
});

it('does not flag a reference that only sometimes looks like a date', function () {
    $warnings = (new MappingGuards)->check(
        [
            'amount' => ['index' => 1, 'header' => 'Venta Bruta'],
            'reference' => ['index' => 2, 'header' => 'ID de la órden'],
        ],
        [
            ['amount' => 2102.0, 'reference' => '2429417418'],
            ['amount' => 500.0, 'reference' => '01/04/2026'],
        ],
    );

    expect($warnings)->toBe([]);
});

it('warns when the mapped amount column yields no numbers at all', function () {
    $warnings = (new MappingGuards)->check(
        ['amount' => ['index' => 3, 'header' => 'Estatus']],
        [['amount' => null], ['amount' => null]],
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toContain('No se encontró');
});
