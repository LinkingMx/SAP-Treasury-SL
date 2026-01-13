<?php

use App\Models\Batch;
use App\Models\Transaction;
use App\Models\User;

test('guests cannot view batch details', function () {
    $batch = Batch::factory()->create();

    $this->getJson(route('batches.show', $batch))
        ->assertUnauthorized();
});

test('authenticated users can view batch details', function () {
    $user = User::factory()->create();
    $batch = Batch::factory()->create();

    $this->actingAs($user)
        ->getJson(route('batches.show', $batch))
        ->assertOk()
        ->assertJsonStructure([
            'id',
            'uuid',
            'filename',
            'total_records',
            'total_debit',
            'total_credit',
            'processed_at',
            'branch',
            'bank_account',
            'user',
            'transactions',
        ]);
});

test('batch details include related transactions', function () {
    $user = User::factory()->create();
    $batch = Batch::factory()->create();
    $transactions = Transaction::factory()->count(3)->create(['batch_id' => $batch->id]);

    $response = $this->actingAs($user)
        ->getJson(route('batches.show', $batch))
        ->assertOk();

    $response->assertJsonCount(3, 'transactions');
    $response->assertJsonStructure([
        'transactions' => [
            '*' => [
                'id',
                'sequence',
                'due_date',
                'memo',
                'debit_amount',
                'credit_amount',
                'counterpart_account',
            ],
        ],
    ]);
});

test('batch details include branch and bank account info', function () {
    $user = User::factory()->create();
    $batch = Batch::factory()->create();

    $response = $this->actingAs($user)
        ->getJson(route('batches.show', $batch))
        ->assertOk();

    $response->assertJsonPath('branch.id', $batch->branch_id);
    $response->assertJsonPath('bank_account.id', $batch->bank_account_id);
});

test('returns 404 for non-existent batch', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('batches.show', 99999))
        ->assertNotFound();
});
