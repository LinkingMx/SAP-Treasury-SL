<?php

use App\Enums\CustomerPaymentBatchStatus;
use App\Jobs\ProcessCustomerPaymentsToSapJob;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CustomerPaymentBatch;
use App\Models\CustomerPaymentInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('guests cannot list customer payment batches', function () {
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);

    $this->getJson(route('customer-payments.index', [
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
    ]))->assertUnauthorized();
});

test('authenticated users can list customer payment batches', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $bankAccount = BankAccount::factory()->create(['branch_id' => $branch->id]);

    CustomerPaymentBatch::factory()->count(3)->create([
        'branch_id' => $branch->id,
        'bank_account_id' => $bankAccount->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('customer-payments.index', [
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
        ]))
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
});

test('authenticated users can view a batch detail with invoices', function () {
    $user = User::factory()->create();
    $batch = CustomerPaymentBatch::factory()->create();
    CustomerPaymentInvoice::factory()->count(2)->create(['batch_id' => $batch->id]);

    $response = $this->actingAs($user)
        ->getJson(route('customer-payments.show', $batch))
        ->assertOk();

    $response->assertJsonCount(2, 'invoices');
    $response->assertJsonPath('branch.id', $batch->branch_id);
});

test('authenticated users can download customer payments template', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('customer-payments.template'))
        ->assertOk()
        ->assertDownload('plantilla_pagos_clientes.xlsx');
});

test('processToSap dispatches the job and flips status to processing', function () {
    Queue::fake();

    $user = User::factory()->create();
    $batch = CustomerPaymentBatch::factory()->create([
        'status' => CustomerPaymentBatchStatus::Pending,
    ]);

    $this->actingAs($user)
        ->postJson(route('customer-payments.process', $batch))
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($batch->fresh()->status)->toBe(CustomerPaymentBatchStatus::Processing);
    Queue::assertPushed(ProcessCustomerPaymentsToSapJob::class);
});

test('processToSap rejects already processing batches', function () {
    $user = User::factory()->create();
    $batch = CustomerPaymentBatch::factory()->create([
        'status' => CustomerPaymentBatchStatus::Processing,
    ]);

    $this->actingAs($user)
        ->postJson(route('customer-payments.process', $batch))
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('processToSap rejects already completed batches', function () {
    $user = User::factory()->create();
    $batch = CustomerPaymentBatch::factory()->create([
        'status' => CustomerPaymentBatchStatus::Completed,
    ]);

    $this->actingAs($user)
        ->postJson(route('customer-payments.process', $batch))
        ->assertStatus(422);
});

test('destroy deletes the batch and its invoices', function () {
    $user = User::factory()->create();
    $batch = CustomerPaymentBatch::factory()->create();
    CustomerPaymentInvoice::factory()->count(2)->create(['batch_id' => $batch->id]);

    $this->actingAs($user)
        ->deleteJson(route('customer-payments.destroy', $batch))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(CustomerPaymentBatch::find($batch->id))->toBeNull();
    expect(CustomerPaymentInvoice::where('batch_id', $batch->id)->count())->toBe(0);
});

test('reprocess refuses when batch is already processing', function () {
    $user = User::factory()->create();
    $batch = CustomerPaymentBatch::factory()->create([
        'status' => CustomerPaymentBatchStatus::Processing,
    ]);

    $this->actingAs($user)
        ->postJson(route('customer-payments.reprocess', ['batch' => $batch->id, 'cardCode' => 'C0001']))
        ->assertStatus(422);
});

test('error log endpoint returns a text attachment', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('customer-payments.error-log'), [
            'errors' => [
                ['row' => 5, 'error' => 'Falta cardcode'],
                ['row' => 0, 'error' => 'Archivo vacio'],
            ],
        ])
        ->assertOk();

    $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toContain('Fila 5: Falta cardcode');
    expect($response->getContent())->toContain('Archivo vacio');
});
