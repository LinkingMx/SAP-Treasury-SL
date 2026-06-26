<?php

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\PaymentOrder;
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

/**
 * Rappi scenario: 3 reconcilable settlements (one cancelled, one orphan) + 3 payments.
 *
 * @return array{branch: Branch, payments: array<int, array<string, mixed>>}
 */
function seedRappiScenario(): array
{
    $acquirer = Acquirer::factory()->delivery('Rappi')->create(['code' => 'RAPPI', 'name' => 'Rappi']);
    $branch = Branch::factory()->create();

    $mk = fn (string $date, string $time, float $amount, string $status, string $ref) => ExternalSettlement::factory()->create([
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'transaction_date' => $date,
        'transaction_time' => $time,
        'amount' => $amount,
        'status' => $status,
        'authorization' => null,
        'reference' => $ref,
    ]);

    $mk('2026-04-01', '22:00:00', 100.0, 'pending_review', 'ORD-1');  // → payment 1
    $mk('2026-04-02', '01:00:00', 200.0, 'pending_review', 'ORD-2');  // business day Apr 1 → payment 2
    $mk('2026-04-03', '12:00:00', 999.0, 'canceled', 'ORD-3');        // excluded
    $mk('2026-04-04', '12:00:00', 500.0, 'pending_review', 'ORD-4');  // orphan

    $payments = [
        gpay(1, 'Rappi', 100.0, '2026-04-01T22:05:00-06:00'),
        gpay(2, 'Rappi', 200.0, '2026-04-02T01:10:00-06:00'),
        gpay(5, 'Rappi', 777.0, '2026-04-10T13:00:00-06:00'),         // pending (no settlement)
    ];

    return ['branch' => $branch, 'payments' => $payments];
}

it('reports matches by business day without writing', function () {
    ['branch' => $branch, 'payments' => $payments] = seedRappiScenario();

    $result = app(AcquirerReconciler::class)->reconcile($branch->id, '2026-04-01', '2026-04-30', $payments);

    expect($result['covered_types'])->toBe(['Rappi'])
        ->and($result['by_type']['Rappi']['matched_count'])->toBe(2)
        ->and((float) $result['by_type']['Rappi']['matched_sum'])->toBe(300.0);

    $rappi = $result['by_acquirer']['RAPPI'];
    expect($rappi['settlements'])->toBe(3)
        ->and($rappi['matched'])->toBe(2)
        ->and($rappi['saved'])->toBe(0)
        ->and($rappi['proposed'])->toBe(2)
        ->and($rappi['orphans'])->toBe(1)
        ->and($rappi['pending'])->toBe(1);

    expect(PaymentOrder::count())->toBe(0);
});

it('persists matches into payment_orders, idempotently, and honors history', function () {
    ['branch' => $branch, 'payments' => $payments] = seedRappiScenario();
    $reconciler = app(AcquirerReconciler::class);

    $first = $reconciler->persist($branch->id, '2026-04-01', '2026-04-30', $payments, 1);

    expect($first['saved'])->toBe(2)
        ->and($first['already'])->toBe(0)
        ->and(PaymentOrder::count())->toBe(2);

    // Rappi has no authorization → external_reference is the order id (reference).
    $po = PaymentOrder::where('parrot_payment_id', 1)->first();
    expect($po->external_reference)->toBe('ORD-1')
        ->and((float) $po->payment_total)->toBe(100.0)
        ->and($po->matched_by_user_id)->toBe(1);

    expect(ExternalSettlement::where('match_status', ExternalSettlement::MATCH_MATCHED)->count())->toBe(2);

    // Re-running persists nothing new.
    $second = $reconciler->persist($branch->id, '2026-04-01', '2026-04-30', $payments, 1);
    expect($second['saved'])->toBe(0)
        ->and($second['already'])->toBe(2)
        ->and(PaymentOrder::count())->toBe(2);

    // Display now counts them as saved history, not proposed.
    $rappi = $reconciler->reconcile($branch->id, '2026-04-01', '2026-04-30', $payments)['by_acquirer']['RAPPI'];
    expect($rappi['saved'])->toBe(2)
        ->and($rappi['proposed'])->toBe(0)
        ->and($rappi['matched'])->toBe(2);
});
