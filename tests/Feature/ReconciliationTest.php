<?php

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use App\Services\ReconciliationService;

test('guests cannot access reconciliation validation page', function () {
    $this->get(route('reconciliation.validation'))
        ->assertRedirect(route('login'));
});

test('authenticated user can access reconciliation validation page', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch->id);

    $this->actingAs($user)
        ->get(route('reconciliation.validation'))
        ->assertOk();
});

test('validate endpoint requires all fields', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch->id);

    $this->actingAs($user)
        ->postJson(route('reconciliation.validation.validate'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id', 'bank_account_id', 'date_from', 'date_to', 'file']);
});

test('validate endpoint rejects bank account without sap_bank_key', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'sap_bank_key' => null,
    ]);

    $user->branches()->attach($branch->id);

    $file = \Illuminate\Http\UploadedFile::fake()->create('extracto.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($user)
        ->postJson(route('reconciliation.validation.validate'), [
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'file' => $file,
        ])
        ->assertUnprocessable();
});

test('validate endpoint rejects date_to before date_from', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->withSapBankKey()->create([
        'branch_id' => $branch->id,
    ]);

    $user->branches()->attach($branch->id);

    $file = \Illuminate\Http\UploadedFile::fake()->create('extracto.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($user)
        ->postJson(route('reconciliation.validation.validate'), [
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'date_from' => '2026-01-31',
            'date_to' => '2026-01-01',
            'file' => $file,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date_to']);
});

test('user cannot validate for unauthorized branch', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->withSapBankKey()->create([
        'branch_id' => $branch->id,
    ]);
    // Not attached to user

    $file = \Illuminate\Http\UploadedFile::fake()->create('extracto.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($user)
        ->postJson(route('reconciliation.validation.validate'), [
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'file' => $file,
        ])
        ->assertForbidden();
});

test('reconcile algorithm matches by date and amount', function () {
    $service = app(ReconciliationService::class);

    $extractoRows = [
        ['due_date' => '2026-01-15', 'debit_amount' => 500.00, 'credit_amount' => 0, 'memo' => 'Pago proveedor'],
        ['due_date' => '2026-01-16', 'debit_amount' => 0, 'credit_amount' => 1000.00, 'memo' => 'Deposito'],
        ['due_date' => '2026-01-17', 'debit_amount' => 200.00, 'credit_amount' => 0, 'memo' => 'Sin match'],
    ];

    $sapRows = [
        ['sequence' => 1, 'account_code' => '1100', 'due_date' => '2026-01-15', 'debit_amount' => 500.00, 'credit_amount' => 0, 'memo' => 'Pago', 'reference' => 'Egreso'],
        ['sequence' => 2, 'account_code' => '1100', 'due_date' => '2026-01-16', 'debit_amount' => 0, 'credit_amount' => 1000.00, 'memo' => 'Dep', 'reference' => 'Ingreso'],
        ['sequence' => 3, 'account_code' => '1100', 'due_date' => '2026-01-18', 'debit_amount' => 300.00, 'credit_amount' => 0, 'memo' => 'Otro', 'reference' => 'Egreso'],
    ];

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('reconcile');
    $method->setAccessible(true);

    $result = $method->invoke($service, $extractoRows, $sapRows);

    expect($result['summary']['total_matched'])->toBe(2);
    expect($result['summary']['total_unmatched_extracto'])->toBe(1);
    expect($result['summary']['total_unmatched_sap'])->toBe(1);
    expect($result['matched'])->toHaveCount(2);
    expect($result['unmatched_extracto'])->toHaveCount(1);
    expect($result['unmatched_extracto'][0]['memo'])->toBe('Sin match');
    expect($result['unmatched_sap'])->toHaveCount(1);
    expect($result['unmatched_sap'][0]['sequence'])->toBe(3);
});

test('reconcile algorithm handles FIFO for duplicate amounts', function () {
    $service = app(ReconciliationService::class);

    $extractoRows = [
        ['due_date' => '2026-01-15', 'debit_amount' => 500.00, 'credit_amount' => 0, 'memo' => 'Primero'],
        ['due_date' => '2026-01-15', 'debit_amount' => 500.00, 'credit_amount' => 0, 'memo' => 'Segundo'],
    ];

    $sapRows = [
        ['sequence' => 1, 'account_code' => '1100', 'due_date' => '2026-01-15', 'debit_amount' => 500.00, 'credit_amount' => 0, 'memo' => 'SAP First', 'reference' => 'Egreso'],
        ['sequence' => 2, 'account_code' => '1100', 'due_date' => '2026-01-15', 'debit_amount' => 500.00, 'credit_amount' => 0, 'memo' => 'SAP Second', 'reference' => 'Egreso'],
    ];

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('reconcile');
    $method->setAccessible(true);

    $result = $method->invoke($service, $extractoRows, $sapRows);

    expect($result['summary']['total_matched'])->toBe(2);
    expect($result['summary']['total_unmatched_extracto'])->toBe(0);
    expect($result['summary']['total_unmatched_sap'])->toBe(0);

    // FIFO: first extracto row matches first SAP row
    expect($result['matched'][0]['sap']['sequence'])->toBe(1);
    expect($result['matched'][1]['sap']['sequence'])->toBe(2);
});

test('export endpoint generates CSV download', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('reconciliation.validation.export'), [
            'matched' => [],
            'unmatched_extracto' => [],
            'unmatched_sap' => [],
            'summary' => [
                'total_extracto' => 0,
                'total_sap' => 0,
                'total_matched' => 0,
                'total_unmatched_extracto' => 0,
                'total_unmatched_sap' => 0,
                'sum_debit_extracto' => 0,
                'sum_credit_extracto' => 0,
                'sum_debit_sap' => 0,
                'sum_credit_sap' => 0,
                'difference' => 0,
            ],
        ])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
