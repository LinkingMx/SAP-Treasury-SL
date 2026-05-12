<?php

use App\Imports\VendorPaymentsImport;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use App\Models\VendorPaymentInvoice;
use Illuminate\Support\Collection;

function makeImport(): VendorPaymentsImport
{
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create();
    $user = User::factory()->create();

    return new VendorPaymentsImport(
        branchId: $branch->id,
        bankAccountId: $bankAccount->id,
        userId: $user->id,
        filename: 'test.xlsx',
        processDate: '2026-05-12',
    );
}

function row(array $overrides = []): array
{
    return array_merge([
        'cardcode' => 'P00073',
        'cardname' => 'LCR ASESORES',
        'docdate_fecha_pago' => '2026-05-12',
        'transferdate' => '2026-05-12',
        'transferaccount' => '1100-200-001',
        'docnum' => 1553,
        'invoicetype' => 'it_PurchaseInvoice',
        'sumapplied' => 29991.50,
        'proveedorref' => 'IN3456',
    ], $overrides);
}

it('imports rows with proveedor_ref persisted', function () {
    $import = makeImport();

    $rows = collect([
        (object) row(['proveedorref' => 'IN3456']),
        (object) row(['docnum' => 1565, 'sumapplied' => 100.00, 'proveedorref' => 'IN9999']),
    ])->map(fn ($r) => new Collection((array) $r));

    $import->collection($rows);

    expect($import->hasErrors())->toBeFalse();

    $invoices = VendorPaymentInvoice::query()->orderBy('line_num')->get();
    expect($invoices)->toHaveCount(2);
    expect($invoices[0]->proveedor_ref)->toBe('IN3456');
    expect($invoices[1]->proveedor_ref)->toBe('IN9999');
});

it('trims whitespace from proveedor_ref', function () {
    $import = makeImport();

    $rows = collect([new Collection(row(['proveedorref' => '  IN3456  ']))]);
    $import->collection($rows);

    expect($import->hasErrors())->toBeFalse();
    expect(VendorPaymentInvoice::first()->proveedor_ref)->toBe('IN3456');
});

it('fails validation when proveedor_ref missing', function () {
    $import = makeImport();

    $rowData = row();
    unset($rowData['proveedorref']);
    $rows = collect([new Collection($rowData)]);

    $import->collection($rows);

    expect($import->hasErrors())->toBeTrue();
    expect($import->getErrors()[0]['error'])
        ->toContain('ProveedorREF');
    expect(VendorPaymentInvoice::count())->toBe(0);
});

it('fails validation when proveedor_ref is blank', function () {
    $import = makeImport();

    $rows = collect([new Collection(row(['proveedorref' => '']))]);
    $import->collection($rows);

    expect($import->hasErrors())->toBeTrue();
    expect(VendorPaymentInvoice::count())->toBe(0);
});

it('fails validation when proveedor_ref exceeds 100 chars', function () {
    $import = makeImport();

    $rows = collect([new Collection(row(['proveedorref' => str_repeat('X', 101)]))]);
    $import->collection($rows);

    expect($import->hasErrors())->toBeTrue();
});
