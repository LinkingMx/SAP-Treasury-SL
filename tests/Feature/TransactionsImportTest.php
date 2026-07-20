<?php

use App\Imports\TransactionsImport;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Transaction;
use App\Models\User;

function importRow(int $sequence, float $debit = 10.0): Illuminate\Support\Collection
{
    return collect([
        'sequence' => $sequence,
        'duedate' => '2026-07-08',
        'memo' => "MOVIMIENTO {$sequence}",
        'cuenta_contrapartida' => '7010-000-001',
        'debit_amount' => $debit,
        'credit_amount' => null,
    ]);
}

function runImport(Illuminate\Support\Collection $rows): TransactionsImport
{
    $branch = Branch::factory()->create();
    $account = BankAccount::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create();

    $import = new TransactionsImport(
        branchId: $branch->id,
        bankAccountId: $account->id,
        userId: $user->id,
        filename: 'lote.xlsx',
    );

    $import->collection($rows);

    return $import;
}

it('bulk-inserts every row across chunk boundaries (more than 500 rows)', function () {
    // 1,164 is the real-world file size that timed out with per-row inserts.
    $rows = collect(range(1, 1164))->map(fn (int $i) => importRow($i));

    $import = runImport($rows);

    expect($import->hasErrors())->toBeFalse();

    $batch = $import->getBatch();
    expect($batch)->not->toBeNull()
        ->and($batch->total_records)->toBe(1164)
        ->and((float) $batch->total_debit)->toBe(11640.0)
        ->and((float) $batch->total_credit)->toBe(0.0);

    $inserted = Transaction::where('batch_id', $batch->id);
    expect($inserted->count())->toBe(1164)
        ->and((clone $inserted)->whereNotNull('created_at')->count())->toBe(1164);

    // Chunk boundaries must not drop or duplicate rows.
    expect((clone $inserted)->distinct()->count('sequence'))->toBe(1164)
        ->and((clone $inserted)->where('sequence', 501)->value('memo'))->toBe('MOVIMIENTO 501');
});

it('imports a small file with the right values and timestamps', function () {
    $import = runImport(collect([importRow(1, 1066.34), importRow(2, 170.59)]));

    $batch = $import->getBatch();
    expect((float) $batch->total_debit)->toBe(1236.93);

    $first = Transaction::where('batch_id', $batch->id)->orderBy('sequence')->first();
    expect($first->memo)->toBe('MOVIMIENTO 1')
        ->and($first->counterpart_account)->toBe('7010-000-001')
        ->and((float) $first->debit_amount)->toBe(1066.34)
        ->and($first->credit_amount)->toBeNull()
        ->and($first->due_date->format('Y-m-d'))->toBe('2026-07-08')
        ->and($first->created_at)->not->toBeNull();
});

it('reports validation errors without creating a batch', function () {
    $bad = collect([collect([
        'sequence' => null,
        'duedate' => '2026-07-08',
        'memo' => '',
        'cuenta_contrapartida' => '',
        'debit_amount' => 10,
        'credit_amount' => null,
    ])]);

    $import = runImport($bad);

    expect($import->hasErrors())->toBeTrue()
        ->and($import->getBatch())->toBeNull()
        ->and(Transaction::count())->toBe(0);
});
