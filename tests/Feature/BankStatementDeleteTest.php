<?php

use App\Enums\BankStatementStatus;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\User;
use App\Services\SapServiceLayer;

test('unauthenticated user cannot delete a bank statement', function () {
    $bankStatement = BankStatement::factory()->sent()->create();

    $this->delete(route('bank-statements.destroy', $bankStatement))
        ->assertRedirect(route('login'));
});

test('user without branch access gets 403 when deleting a bank statement', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);

    $bankStatement = BankStatement::factory()->sent()->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
        'payload' => [
            'BankPages' => [
                ['sap_sequence' => 100],
            ],
        ],
    ]);

    // User is NOT attached to the branch
    $this->actingAs($user)
        ->deleteJson(route('bank-statements.destroy', $bankStatement))
        ->assertForbidden()
        ->assertJson(['success' => false]);
});

test('deleting a non-existent bank statement returns error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson(route('bank-statements.destroy', 999999))
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

test('deleting a statement with no sap sequences in payload returns 422', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);
    $user->branches()->attach($branch->id);

    $bankStatement = BankStatement::factory()->sent()->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
        'payload' => [
            'BankPages' => [
                ['memo' => 'no sequence here'],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->deleteJson(route('bank-statements.destroy', $bankStatement))
        ->assertUnprocessable()
        ->assertJson(['success' => false]);
});

test('happy path: user with branch access deletes a sent statement successfully', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['sap_database' => 'TEST_DB']);
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);
    $user->branches()->attach($branch->id);

    $bankStatement = BankStatement::factory()->sent()->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
        'payload' => [
            'BankPages' => [
                ['sap_sequence' => 100],
                ['sap_sequence' => 101],
                ['sap_sequence' => 102],
            ],
        ],
    ]);

    $this->mock(SapServiceLayer::class, function ($mock) {
        $mock->shouldReceive('login')
            ->once()
            ->with('TEST_DB')
            ->andReturn(true);

        $mock->shouldReceive('deleteBankPages')
            ->once()
            ->with([100, 101, 102])
            ->andReturn([
                'success' => true,
                'deleted_count' => 3,
                'failed_count' => 0,
                'errors' => [],
            ]);

        $mock->shouldReceive('logout')->once();
    });

    $this->actingAs($user)
        ->deleteJson(route('bank-statements.destroy', $bankStatement))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'deleted_count' => 3,
        ]);

    $bankStatement->refresh();
    expect($bankStatement->status)->toBe(BankStatementStatus::Cancelled);
});

test('partial failure from SAP still marks statement as cancelled', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['sap_database' => 'TEST_DB']);
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);
    $user->branches()->attach($branch->id);

    $bankStatement = BankStatement::factory()->sent()->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
        'payload' => [
            'BankPages' => [
                ['sap_sequence' => 200],
                ['sap_sequence' => 201],
                ['sap_sequence' => 202],
            ],
        ],
    ]);

    $this->mock(SapServiceLayer::class, function ($mock) {
        $mock->shouldReceive('login')
            ->once()
            ->with('TEST_DB')
            ->andReturn(true);

        $mock->shouldReceive('deleteBankPages')
            ->once()
            ->with([200, 201, 202])
            ->andReturn([
                'success' => false,
                'deleted_count' => 2,
                'failed_count' => 1,
                'errors' => ['Sequence 202: Not found'],
            ]);

        $mock->shouldReceive('logout')->once();
    });

    $this->actingAs($user)
        ->deleteJson(route('bank-statements.destroy', $bankStatement))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'deleted_count' => 2,
            'failed_count' => 1,
        ]);

    $bankStatement->refresh();
    expect($bankStatement->status)->toBe(BankStatementStatus::Cancelled);
});

test('SAP login failure returns 500', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['sap_database' => 'TEST_DB']);
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);
    $user->branches()->attach($branch->id);

    $bankStatement = BankStatement::factory()->sent()->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
        'payload' => [
            'BankPages' => [
                ['sap_sequence' => 300],
            ],
        ],
    ]);

    $this->mock(SapServiceLayer::class, function ($mock) {
        $mock->shouldReceive('login')
            ->once()
            ->with('TEST_DB')
            ->andReturn(false);
    });

    $this->actingAs($user)
        ->deleteJson(route('bank-statements.destroy', $bankStatement))
        ->assertStatus(500)
        ->assertJson(['success' => false]);

    // Status should NOT change to cancelled
    $bankStatement->refresh();
    expect($bankStatement->status)->toBe(BankStatementStatus::Sent);
});
