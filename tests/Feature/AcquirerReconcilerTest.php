<?php

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Services\Acquirer\AcquirerReconciler;

function gpay(int $id, string $type, float $total, string $createdAtPos, string $status = 'CHARGED'): array
{
    return [
        'id' => $id,
        'payment_type_name' => $type,
        'amount' => $total,
        'tip' => 0,
        'total' => $total,
        'status' => $status,
        'created_at_pos' => $createdAtPos,
        'order_reference' => 'R-'.$id,
    ];
}

it('reconciles loaded settlements against payments by business day', function () {
    $acquirer = Acquirer::factory()->delivery('Rappi')->create(['code' => 'RAPPI', 'name' => 'Rappi']);
    $branch = Branch::factory()->create();

    $make = fn (string $date, string $time, float $amount, string $status) => ExternalSettlement::factory()->create([
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'transaction_date' => $date,
        'transaction_time' => $time,
        'amount' => $amount,
        'status' => $status,
    ]);

    $make('2026-04-01', '22:00:00', 100.0, 'pending_review');   // → payment 1
    $make('2026-04-02', '01:00:00', 200.0, 'pending_review');   // business day Apr 1 → payment 2
    $make('2026-04-03', '12:00:00', 999.0, 'canceled');         // excluded (cancelled)
    $make('2026-04-04', '12:00:00', 500.0, 'pending_review');   // orphan (no payment)

    $payments = [
        gpay(1, 'Rappi', 100.0, '2026-04-01T22:05:00-06:00'),
        gpay(2, 'Rappi', 200.0, '2026-04-02T01:10:00-06:00'),   // business day Apr 1
        gpay(5, 'Rappi', 777.0, '2026-04-10T13:00:00-06:00'),   // pending (no settlement)
    ];

    $result = app(AcquirerReconciler::class)->reconcile($branch->id, '2026-04-01', '2026-04-30', $payments);

    expect($result['covered_types'])->toBe(['Rappi'])
        ->and($result['by_type']['Rappi']['matched_count'])->toBe(2)
        ->and((float) $result['by_type']['Rappi']['matched_sum'])->toBe(300.0)
        ->and($result['matched_payment_ids'])->toEqualCanonicalizing([1, 2]);

    $rappi = $result['by_acquirer']['RAPPI'];
    expect($rappi['settlements'])->toBe(3)   // cancelled one excluded
        ->and($rappi['matched'])->toBe(2)
        ->and($rappi['orphans'])->toBe(1)
        ->and($rappi['pending'])->toBe(1);
});
