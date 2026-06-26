<?php

use App\Enums\SettlementUploadStatus;
use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\PaymentOrder;
use App\Models\SettlementUpload;
use App\Models\User;
use App\Services\Acquirer\AcquirerReconciliationService;
use Illuminate\Support\Facades\Http;

function fakeGcorePayments(array $data): void
{
    Http::fake([
        '*/api/parrot-order-payments*' => Http::response([
            'filters' => [],
            'summary' => ['count' => count($data)],
            'data' => $data,
            'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => count($data)],
        ]),
    ]);
}

function makeUpload(): SettlementUpload
{
    $acquirer = Acquirer::factory()->bank()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);

    return SettlementUpload::factory()->create([
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'user_id' => User::factory()->create()->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);
}

$rows = [
    ['transaction_date' => '2026-05-15', 'transaction_time' => '12:00:00', 'amount' => 1000.00, 'authorization' => 'AUTH001', 'raw' => ['x']],
    ['transaction_date' => '2026-05-16', 'transaction_time' => null, 'amount' => 2500.00, 'authorization' => 'AUTH002', 'raw' => ['y']],
    ['transaction_date' => '2026-05-17', 'transaction_time' => null, 'amount' => 9999.00, 'authorization' => 'AUTH003', 'raw' => ['z']], // no payment
];

it('reconciles settlement rows against gCore payments and flags matches', function () use ($rows) {
    fakeGcorePayments([
        ['id' => 11, 'payment_type_name' => 'CREDITO', 'total' => 1000.00, 'status' => 'CHARGED', 'created_at_pos' => '2026-05-15T12:01:00-06:00', 'order_reference' => 'R-1'],
        ['id' => 12, 'payment_type_name' => 'DEBITO', 'total' => 2500.00, 'status' => 'CHARGED', 'created_at_pos' => '2026-05-16T18:00:00-06:00', 'order_reference' => 'R-2'],
    ]);

    $upload = makeUpload();
    $result = app(AcquirerReconciliationService::class)->reconcile($upload, $rows);

    expect($result->total)->toBe(3)
        ->and($result->matched)->toBe(2)
        ->and($result->unmatched())->toBe(1);

    $upload->refresh();
    expect($upload->status)->toBe(SettlementUploadStatus::Done)
        ->and($upload->matched_rows)->toBe(2)
        ->and($upload->unmatched_rows)->toBe(1);

    expect(PaymentOrder::count())->toBe(2)
        ->and(PaymentOrder::where('parrot_payment_id', 11)->value('external_reference'))->toBe('AUTH001')
        ->and(ExternalSettlement::where('match_status', ExternalSettlement::MATCH_MATCHED)->count())->toBe(2)
        ->and(ExternalSettlement::where('match_status', ExternalSettlement::MATCH_UNMATCHED)->count())->toBe(1);
});

it('is idempotent — reprocessing does not duplicate', function () use ($rows) {
    fakeGcorePayments([
        ['id' => 11, 'payment_type_name' => 'CREDITO', 'total' => 1000.00, 'status' => 'CHARGED', 'created_at_pos' => '2026-05-15T12:01:00-06:00', 'order_reference' => 'R-1'],
    ]);

    $upload = makeUpload();
    $service = app(AcquirerReconciliationService::class);

    $service->reconcile($upload, $rows);
    $service->reconcile($upload, $rows);

    expect(PaymentOrder::count())->toBe(1)
        ->and(ExternalSettlement::count())->toBe(3);
});

it('throws when the branch has no payment_branch', function () use ($rows) {
    fakeGcorePayments([]);

    $acquirer = Acquirer::factory()->bank()->create();
    $branch = Branch::factory()->create(['payment_branch' => null]);
    $upload = SettlementUpload::factory()->create([
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'user_id' => User::factory()->create()->id,
    ]);

    expect(fn () => app(AcquirerReconciliationService::class)->reconcile($upload, $rows))
        ->toThrow(RuntimeException::class);
});
