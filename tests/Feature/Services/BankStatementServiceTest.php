<?php

use App\Models\BankStatement;
use App\Models\Branch;
use App\Services\BankStatementService;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('transformToSapFormat generates correct BankPages structure', function () {
    $service = app(BankStatementService::class);
    $glAccountCode = '1020-001-000';

    $transactions = [
        [
            'due_date' => '2026-01-15',
            'memo' => 'Test transaction 1',
            'debit_amount' => 1000.50,
            'credit_amount' => 0,
        ],
        [
            'due_date' => '2026-01-16',
            'memo' => 'Test transaction 2',
            'debit_amount' => 0,
            'credit_amount' => 500.25,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    expect($result)->toHaveCount(2);

    // First transaction (debit = Egreso)
    expect($result[0])->toHaveKey('DueDate');
    expect($result[0])->toHaveKey('Memo');
    expect($result[0])->toHaveKey('DebitAmount');
    expect($result[0])->toHaveKey('CreditAmount');
    expect($result[0])->toHaveKey('DocNumberType');
    expect($result[0])->toHaveKey('AccountCode');
    expect($result[0])->toHaveKey('Reference');
    expect($result[0]['DueDate'])->toBe('2026-01-15T00:00:00Z');
    expect($result[0]['DebitAmount'])->toBe(1000.5);
    expect($result[0]['CreditAmount'])->toBe(0.0);
    expect($result[0]['DocNumberType'])->toBe('bpdt_DocNum');
    expect($result[0]['AccountCode'])->toBe($glAccountCode);
    expect($result[0]['Reference'])->toBe('Egreso');

    // Second transaction (credit = Ingreso)
    expect($result[1]['DueDate'])->toBe('2026-01-16T00:00:00Z');
    expect($result[1]['DebitAmount'])->toBe(0.0);
    expect($result[1]['CreditAmount'])->toBe(500.25);
    expect($result[1]['AccountCode'])->toBe($glAccountCode);
    expect($result[1]['Reference'])->toBe('Ingreso');
});

test('generateStatementNumber follows YYYY-MM-XXX format', function () {
    $service = app(BankStatementService::class);
    $branch = Branch::factory()->create();
    $date = Carbon::parse('2026-01-15');

    $result = $service->generateStatementNumber($branch->id, $date);

    expect($result)->toMatch('/^2026-01-\d{3}$/');
    expect($result)->toBe('2026-01-001');
});

test('generateStatementNumber increments sequence within same month', function () {
    $service = app(BankStatementService::class);
    $branch = Branch::factory()->create();
    $date = Carbon::parse('2026-02-15');

    // Create existing statements for this month
    BankStatement::factory()->create([
        'branch_id' => $branch->id,
        'statement_number' => '2026-02-001',
    ]);
    BankStatement::factory()->create([
        'branch_id' => $branch->id,
        'statement_number' => '2026-02-002',
    ]);

    $result = $service->generateStatementNumber($branch->id, $date);

    expect($result)->toBe('2026-02-003');
});

test('transformToSapFormat preserves full Memo without truncation', function () {
    $service = app(BankStatementService::class);
    $longMemo = str_repeat('A', 300);
    $glAccountCode = '1020-001-000';

    $transactions = [
        [
            'due_date' => '2026-01-15',
            'memo' => $longMemo,
            'debit_amount' => 100,
            'credit_amount' => 0,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    expect(strlen($result[0]['Memo']))->toBe(300);
});

test('transformToSapFormat adds timestamp to date without one', function () {
    $service = app(BankStatementService::class);
    $glAccountCode = '1020-001-000';

    $transactions = [
        [
            'due_date' => '2026-01-15',
            'memo' => 'Test',
            'debit_amount' => 100,
            'credit_amount' => 0,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    // Should add the timestamp component
    expect($result[0]['DueDate'])->toBe('2026-01-15T00:00:00Z');
    expect($result[0]['DueDate'])->toContain('T');
});

test('transformToSapFormat preserves existing timestamp', function () {
    $service = app(BankStatementService::class);
    $glAccountCode = '1020-001-000';

    $transactions = [
        [
            'due_date' => '2026-01-15T10:30:00Z',
            'memo' => 'Test',
            'debit_amount' => 100,
            'credit_amount' => 0,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    // Should preserve the existing timestamp
    expect($result[0]['DueDate'])->toBe('2026-01-15T10:30:00Z');
});

test('transformToSapFormat handles mixed transactions correctly', function () {
    $service = app(BankStatementService::class);
    $glAccountCode = '1020-001-000';

    $transactions = [
        [
            'due_date' => '2026-01-15',
            'memo' => 'Pure debit',
            'debit_amount' => 500,
            'credit_amount' => 0,
        ],
        [
            'due_date' => '2026-01-16',
            'memo' => 'Pure credit',
            'debit_amount' => 0,
            'credit_amount' => 300,
        ],
        [
            'due_date' => '2026-01-17',
            'memo' => 'Zero amount',
            'debit_amount' => 0,
            'credit_amount' => 0,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    // All rows use the same GL account code
    expect($result[0]['AccountCode'])->toBe($glAccountCode);
    expect($result[1]['AccountCode'])->toBe($glAccountCode);
    expect($result[2]['AccountCode'])->toBe($glAccountCode);

    // Debit transaction = Egreso
    expect($result[0]['DebitAmount'])->toBe(500.0);
    expect($result[0]['CreditAmount'])->toBe(0.0);
    expect($result[0]['Reference'])->toBe('Egreso');

    // Credit transaction = Ingreso
    expect($result[1]['DebitAmount'])->toBe(0.0);
    expect($result[1]['CreditAmount'])->toBe(300.0);
    expect($result[1]['Reference'])->toBe('Ingreso');

    // Zero amount defaults to Ingreso (since debit is not > 0)
    expect($result[2]['DebitAmount'])->toBe(0.0);
    expect($result[2]['CreditAmount'])->toBe(0.0);
    expect($result[2]['Reference'])->toBe('Ingreso');
});

test('transformToSapFormat uses provided GL account code for all rows', function () {
    $service = app(BankStatementService::class);
    $glAccountCode = '9999-999-999';

    $transactions = [
        [
            'due_date' => '2026-01-15',
            'memo' => 'Debit transaction',
            'debit_amount' => 100,
            'credit_amount' => 0,
        ],
        [
            'due_date' => '2026-01-15',
            'memo' => 'Credit transaction',
            'debit_amount' => 0,
            'credit_amount' => 200,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    // Both rows use the same GL account code from the bank account
    expect($result[0]['AccountCode'])->toBe($glAccountCode);
    expect($result[1]['AccountCode'])->toBe($glAccountCode);
});

test('transformToSapFormat returns float amounts not strings', function () {
    $service = app(BankStatementService::class);
    $glAccountCode = '1020-001-000';

    $transactions = [
        [
            'due_date' => '2026-01-15',
            'memo' => 'Test',
            'debit_amount' => 100.50,
            'credit_amount' => 0,
        ],
    ];

    $result = $service->transformToSapFormat($transactions, $glAccountCode);

    expect($result[0]['DebitAmount'])->toBeFloat();
    expect($result[0]['CreditAmount'])->toBeFloat();
});
