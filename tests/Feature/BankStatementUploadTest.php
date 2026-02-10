<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\User;
use App\Services\BankStatementService;
use Mockery\MockInterface;

test('authenticated user can access history endpoint', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();

    $user->branches()->attach($branch->id);

    $this->mock(BankStatementService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getHistory')
            ->once()
            ->andReturn(collect([]));
    });

    $this->actingAs($user)
        ->getJson(route('bank-statements.history', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertJsonStructure([
            'success',
            'history',
        ]);
});

test('user cannot access history of unauthorized branch', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    // Not attached to user

    $this->mock(BankStatementService::class);

    $this->actingAs($user)
        ->getJson(route('bank-statements.history', ['branch_id' => $branch->id]))
        ->assertForbidden();
});

test('send endpoint requires bank account with sap_bank_key', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'sap_bank_key' => null,
    ]);

    $user->branches()->attach($branch->id);

    $this->mock(BankStatementService::class);

    $this->actingAs($user)
        ->postJson(route('bank-statements.send'), [
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'statement_date' => '2026-01-15',
            'filename' => 'test.xlsx',
            'transactions' => [
                [
                    'due_date' => '2026-01-15',
                    'memo' => 'Test transaction',
                    'debit_amount' => 100,
                    'credit_amount' => 0,
                    'sap_account_code' => null,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonFragment([
            'message' => 'La cuenta bancaria no tiene configurada la Clave Bancaria SAP (sap_bank_key).',
        ]);
});

test('send endpoint requires bank account to belong to branch', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->withSapBankKey()->create([
        'branch_id' => $otherBranch->id,
    ]);

    $user->branches()->attach($branch->id);
    $user->branches()->attach($otherBranch->id);

    $this->mock(BankStatementService::class);

    $this->actingAs($user)
        ->postJson(route('bank-statements.send'), [
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'statement_date' => '2026-01-15',
            'filename' => 'test.xlsx',
            'transactions' => [
                [
                    'due_date' => '2026-01-15',
                    'memo' => 'Test transaction',
                    'debit_amount' => 100,
                    'credit_amount' => 0,
                    'sap_account_code' => null,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonFragment([
            'message' => 'La cuenta bancaria no pertenece a esta sucursal.',
        ]);
});

test('history returns bank statements correctly', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->withSapBankKey()->create([
        'branch_id' => $branch->id,
    ]);

    $user->branches()->attach($branch->id);

    // Create some statements
    $statements = BankStatement::factory()->count(3)->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
    ]);

    $this->mock(BankStatementService::class, function (MockInterface $mock) use ($statements) {
        $mock->shouldReceive('getHistory')
            ->once()
            ->andReturn($statements->load(['bankAccount', 'user']));
    });

    $response = $this->actingAs($user)
        ->getJson(route('bank-statements.history', ['branch_id' => $branch->id]))
        ->assertOk();

    expect($response->json('history'))->toHaveCount(3);
    expect($response->json('history.0'))->toHaveKeys([
        'id',
        'statement_number',
        'statement_date',
        'original_filename',
        'rows_count',
        'status',
        'status_label',
        'sap_doc_entry',
        'bank_account',
        'user',
        'created_at',
    ]);
});

test('bank statement model has correct relationships', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->withSapBankKey()->create([
        'branch_id' => $branch->id,
    ]);

    $statement = BankStatement::factory()->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
    ]);

    expect($statement->branch)->toBeInstanceOf(Branch::class);
    expect($statement->bankAccount)->toBeInstanceOf(BankAccount::class);
    expect($statement->user)->toBeInstanceOf(User::class);
});

test('bank statement scope forBranch filters correctly', function () {
    $branch1 = Branch::factory()->create();
    $branch2 = Branch::factory()->create();

    BankStatement::factory()->count(3)->create(['branch_id' => $branch1->id]);
    BankStatement::factory()->count(2)->create(['branch_id' => $branch2->id]);

    $result = BankStatement::forBranch($branch1->id)->get();

    expect($result)->toHaveCount(3);
});

test('bank statement factory creates valid record', function () {
    $statement = BankStatement::factory()->create();

    expect($statement)->toBeInstanceOf(BankStatement::class);
    expect($statement->statement_number)->toMatch('/^\d{4}-\d{2}-\d{3}$/');
    expect($statement->rows_count)->toBeGreaterThan(0);
});

test('bank statement factory sent state works correctly', function () {
    $statement = BankStatement::factory()->sent()->create();

    expect($statement->status->value)->toBe('sent');
    expect($statement->sap_doc_entry)->not->toBeNull();
    expect($statement->sap_error)->toBeNull();
});

test('bank statement factory failed state works correctly', function () {
    $statement = BankStatement::factory()->failed()->create();

    expect($statement->status->value)->toBe('failed');
    expect($statement->sap_doc_entry)->toBeNull();
    expect($statement->sap_error)->not->toBeNull();
});
