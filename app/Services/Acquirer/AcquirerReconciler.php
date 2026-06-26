<?php

namespace App\Services\Acquirer;

use App\Models\Acquirer;
use App\Models\ExternalSettlement;
use App\Models\PaymentOrder;
use App\Services\GcorePaymentsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the loaded external_settlements against a set of gCore Parrot
 * payments, by restaurant business day. Honors already-saved payment_orders as
 * history; `reconcile()` reports (saved + proposed) without writing, while
 * `persist()` writes the new matches into payment_orders.
 */
final class AcquirerReconciler
{
    public function __construct(private AcquirerMatcher $matcher) {}

    /**
     * On-the-fly reconciliation summary (no writes).
     *
     * @param  array<int, array<string, mixed>>  $gcorePayments
     * @return array{
     *   matched_payment_ids: array<int, int>,
     *   covered_types: array<int, string>,
     *   by_type: array<string, array{matched_count: int, matched_sum: float, saved_count: int, proposed_count: int}>,
     *   by_acquirer: array<string, array{name: string, settlements: int, saved: int, proposed: int, matched: int, orphans: int, pending: int}>
     * }
     */
    public function reconcile(int $branchId, string $from, string $to, array $gcorePayments): array
    {
        $c = $this->computeMatches($branchId, $from, $to, $gcorePayments);

        return [
            'matched_payment_ids' => array_values(array_unique(array_merge($c['saved_payment_ids'], $c['proposed_payment_ids']))),
            'covered_types' => $c['covered_types'],
            'by_type' => $c['by_type'],
            'by_acquirer' => $c['by_acquirer'],
        ];
    }

    /**
     * Persist the proposed (not yet saved) matches into payment_orders.
     * Idempotent — already-saved links are left untouched.
     *
     * @param  array<int, array<string, mixed>>  $gcorePayments
     * @return array{saved: int, already: int, total_matched: int}
     */
    public function persist(int $branchId, string $from, string $to, array $gcorePayments, ?int $userId = null): array
    {
        $c = $this->computeMatches($branchId, $from, $to, $gcorePayments);

        $saved = DB::transaction(function () use ($c, $branchId, $userId): int {
            $count = 0;

            foreach ($c['proposed_pairs'] as $pair) {
                $settlement = $pair['settlement'];
                $result = $pair['payment'];

                PaymentOrder::firstOrCreate(
                    ['external_settlement_id' => $settlement['id']],
                    [
                        'upload_id' => $settlement['upload_id'],
                        'acquirer_id' => $settlement['acquirer_id'],
                        'branch_id' => $branchId,
                        'parrot_payment_id' => $result->parrotPaymentId,
                        'order_reference' => $result->orderReference,
                        'payment_total' => $result->paymentTotal,
                        'external_reference' => $settlement['authorization'] ?? $settlement['reference'],
                        'match_method' => $result->method,
                        'match_diff' => $result->diff,
                        'matched_at' => now(),
                        'matched_by_user_id' => $userId,
                    ],
                );

                ExternalSettlement::whereKey($settlement['id'])
                    ->update(['match_status' => ExternalSettlement::MATCH_MATCHED]);

                $count++;
            }

            return $count;
        });

        $already = count($c['saved_payment_ids']);

        return ['saved' => $saved, 'already' => $already, 'total_matched' => $saved + $already];
    }

    /**
     * Core matching: honors saved payment_orders, proposes the rest.
     *
     * @param  array<int, array<string, mixed>>  $gcorePayments
     * @return array{
     *   covered_types: array<int, string>,
     *   saved_payment_ids: array<int, int>,
     *   proposed_payment_ids: array<int, int>,
     *   proposed_pairs: array<int, array{settlement: array<string, mixed>, payment: MatchResult}>,
     *   by_type: array<string, array<string, mixed>>,
     *   by_acquirer: array<string, array<string, mixed>>
     * }
     */
    private function computeMatches(int $branchId, string $from, string $to, array $gcorePayments): array
    {
        $startHour = (int) substr((string) config('services.gcore.business_day_start', '05:00:00'), 0, 2);

        $paymentTypeById = [];
        foreach ($gcorePayments as $payment) {
            $paymentTypeById[(int) ($payment['id'] ?? 0)] = $payment['payment_type_name'] ?? 'Sin tipo';
        }

        $rowsByAcquirer = $this->loadSettlementRows($branchId, $from, $to, $startHour);

        // Already-saved links whose payment falls in this window = history.
        $existing = PaymentOrder::query()
            ->where('branch_id', $branchId)
            ->whereIn('parrot_payment_id', array_keys($paymentTypeById) ?: [0])
            ->get(['external_settlement_id', 'parrot_payment_id', 'payment_total', 'acquirer_id']);

        $savedSettlementIds = $existing->pluck('external_settlement_id')->map(fn ($id): int => (int) $id)->flip();
        $savedPaymentIds = $existing->pluck('parrot_payment_id')->map(fn ($id): int => (int) $id)->values()->all();
        $savedByAcquirer = $existing->groupBy('acquirer_id')->map->count();

        $byType = [];
        foreach ($existing as $link) {
            $type = $paymentTypeById[(int) $link->parrot_payment_id] ?? 'Sin tipo';
            $byType[$type] ??= ['matched_count' => 0, 'matched_sum' => 0.0, 'saved_count' => 0, 'proposed_count' => 0];
            $byType[$type]['matched_count']++;
            $byType[$type]['saved_count']++;
            $byType[$type]['matched_sum'] += (float) $link->payment_total;
        }

        $coveredTypes = [];
        $proposedPaymentIds = [];
        $proposedPairs = [];
        $byAcquirer = [];

        foreach ($rowsByAcquirer as $acquirerId => $rows) {
            $acquirer = Acquirer::find($acquirerId);
            if ($acquirer === null) {
                continue;
            }

            $rule = $acquirer->matchRule();
            $coveredTypes = array_merge($coveredTypes, $rule->parrotTypes);

            $candidates = array_values(array_filter(
                $gcorePayments,
                static fn (array $p): bool => in_array($p['payment_type_name'] ?? '', $rule->parrotTypes, true),
            ));
            $chargedCandidates = array_filter($candidates, static fn (array $p): bool => ($p['status'] ?? null) === 'CHARGED');

            $unsaved = array_values(array_filter($rows, fn (array $r): bool => ! isset($savedSettlementIds[$r['id']])));

            $excluded = array_merge($savedPaymentIds, $proposedPaymentIds);
            $results = $this->matcher->match($unsaved, $candidates, $rule, $excluded, $startHour);

            $proposed = 0;
            foreach ($results as $result) {
                if (! $result->matched()) {
                    continue;
                }

                $proposed++;
                $proposedPaymentIds[] = $result->parrotPaymentId;
                $proposedPairs[] = ['settlement' => $unsaved[$result->rowIndex], 'payment' => $result];

                $type = $paymentTypeById[$result->parrotPaymentId] ?? 'Sin tipo';
                $byType[$type] ??= ['matched_count' => 0, 'matched_sum' => 0.0, 'saved_count' => 0, 'proposed_count' => 0];
                $byType[$type]['matched_count']++;
                $byType[$type]['proposed_count']++;
                $byType[$type]['matched_sum'] += (float) $result->paymentTotal;
            }

            $saved = (int) ($savedByAcquirer[$acquirerId] ?? 0);
            $matched = $saved + $proposed;

            $byAcquirer[$acquirer->code] = [
                'name' => $acquirer->name,
                'settlements' => count($rows),
                'saved' => $saved,
                'proposed' => $proposed,
                'matched' => $matched,
                'orphans' => count($rows) - $matched,
                'pending' => count($chargedCandidates) - $matched,
            ];
        }

        return [
            'covered_types' => array_values(array_unique($coveredTypes)),
            'saved_payment_ids' => $savedPaymentIds,
            'proposed_payment_ids' => $proposedPaymentIds,
            'proposed_pairs' => $proposedPairs,
            'by_type' => $byType,
            'by_acquirer' => $byAcquirer,
        ];
    }

    /**
     * Load non-cancelled settlement rows for the branch whose business day falls
     * within [from, to], grouped by acquirer_id. Each row carries the identifiers
     * needed to persist a payment_order.
     *
     * @return array<int, array<int, array{id: int, upload_id: int, acquirer_id: int, reference: string|null, authorization: string|null, transaction_date: string, transaction_time: string|null, amount: float}>>
     */
    private function loadSettlementRows(int $branchId, string $from, string $to, int $startHour): array
    {
        $upperBound = Carbon::parse($to)->addDay()->format('Y-m-d');

        $settlements = ExternalSettlement::query()
            ->where('branch_id', $branchId)
            ->whereBetween('transaction_date', [$from, $upperBound])
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', 'not like', '%cancel%');
            })
            ->get(['id', 'upload_id', 'acquirer_id', 'reference', 'authorization', 'transaction_date', 'transaction_time', 'amount', 'status']);

        $grouped = [];

        foreach ($settlements as $settlement) {
            $businessDay = GcorePaymentsService::businessDay(
                $settlement->transaction_date->format('Y-m-d').' '.($settlement->transaction_time ?? '00:00:00'),
            );

            if ($businessDay < $from || $businessDay > $to) {
                continue;
            }

            $grouped[$settlement->acquirer_id][] = [
                'id' => (int) $settlement->id,
                'upload_id' => (int) $settlement->upload_id,
                'acquirer_id' => (int) $settlement->acquirer_id,
                'reference' => $settlement->reference,
                'authorization' => $settlement->authorization,
                'transaction_date' => $settlement->transaction_date->format('Y-m-d'),
                'transaction_time' => $settlement->transaction_time,
                'amount' => (float) $settlement->amount,
                'status' => $settlement->status,
            ];
        }

        return $grouped;
    }

    /**
     * Row-level reconciliation detail for one payment type (drill-down).
     *
     * @param  array<int, array<string, mixed>>  $gcorePayments
     * @return array{
     *   matched: array<int, array<string, mixed>>,
     *   orphans: array<int, array<string, mixed>>,
     *   pending: array<int, array<string, mixed>>,
     *   summary: array{matched: int, orphans: int, pending: int, saved: int}
     * }
     */
    public function detail(int $branchId, string $from, string $to, array $gcorePayments, string $paymentType): array
    {
        $startHour = (int) substr((string) config('services.gcore.business_day_start', '05:00:00'), 0, 2);

        $paymentById = [];
        foreach ($gcorePayments as $payment) {
            $paymentById[(int) ($payment['id'] ?? 0)] = $payment;
        }

        $acquirerIds = Acquirer::all()
            ->filter(fn (Acquirer $a): bool => in_array($paymentType, $a->parrot_payment_type_names ?? [], true))
            ->pluck('id')
            ->all();

        $rowsByAcquirer = $this->loadSettlementRows($branchId, $from, $to, $startHour);

        $existing = PaymentOrder::query()
            ->where('branch_id', $branchId)
            ->whereIn('parrot_payment_id', array_keys($paymentById) ?: [0])
            ->get(['external_settlement_id', 'parrot_payment_id']);
        $savedBySettlement = $existing->keyBy('external_settlement_id');

        $matched = [];
        $orphans = [];
        $usedPaymentIds = $existing->pluck('parrot_payment_id')->map(fn ($id): int => (int) $id)->all();
        $savedCount = 0;

        foreach ($acquirerIds as $acquirerId) {
            $rows = $rowsByAcquirer[$acquirerId] ?? [];
            if ($rows === []) {
                continue;
            }

            $acquirer = Acquirer::find($acquirerId);
            $rule = $acquirer->matchRule();
            $candidates = array_values(array_filter(
                $gcorePayments,
                static fn (array $p): bool => in_array($p['payment_type_name'] ?? '', $rule->parrotTypes, true),
            ));

            // Already-saved links for these settlements (only of this payment type).
            foreach ($rows as $row) {
                $link = $savedBySettlement->get($row['id']);
                if ($link === null) {
                    continue;
                }
                $pid = (int) $link->parrot_payment_id;
                if (($paymentById[$pid]['payment_type_name'] ?? null) !== $paymentType) {
                    continue;
                }
                $matched[] = $this->pairDetail($row, $paymentById[$pid] ?? null, $pid, true);
                $savedCount++;
            }

            // Match the unsaved rows; collect proposed + orphans.
            $unsaved = array_values(array_filter($rows, fn (array $r): bool => ! $savedBySettlement->has($r['id'])));
            $results = $this->matcher->match($unsaved, $candidates, $rule, $usedPaymentIds, $startHour);

            foreach ($results as $result) {
                $row = $unsaved[$result->rowIndex];

                if (! $result->matched()) {
                    $orphans[] = [
                        'id' => $row['id'],
                        'transaction_date' => $row['transaction_date'],
                        'transaction_time' => $row['transaction_time'],
                        'amount' => $row['amount'],
                        'reference' => $row['reference'],
                        'authorization' => $row['authorization'],
                        'status' => $row['status'] ?? null,
                    ];

                    continue;
                }

                $usedPaymentIds[] = $result->parrotPaymentId;
                if (($paymentById[$result->parrotPaymentId]['payment_type_name'] ?? null) !== $paymentType) {
                    continue;
                }
                $matched[] = $this->pairDetail($row, $paymentById[$result->parrotPaymentId] ?? null, $result->parrotPaymentId, false);
            }
        }

        $used = array_flip($usedPaymentIds);
        $pending = [];
        foreach ($gcorePayments as $payment) {
            if (($payment['payment_type_name'] ?? null) !== $paymentType || ($payment['status'] ?? null) !== 'CHARGED') {
                continue;
            }
            if (isset($used[(int) ($payment['id'] ?? 0)])) {
                continue;
            }
            $pending[] = [
                'id' => (int) ($payment['id'] ?? 0),
                'created_at_pos' => $payment['created_at_pos'] ?? null,
                'business_day' => GcorePaymentsService::businessDay((string) ($payment['created_at_pos'] ?? '')),
                'total' => (float) ($payment['total'] ?? 0),
                'order_reference' => $payment['order_reference'] ?? null,
            ];
        }

        return [
            'matched' => $matched,
            'orphans' => $orphans,
            'pending' => $pending,
            'summary' => ['matched' => count($matched), 'orphans' => count($orphans), 'pending' => count($pending), 'saved' => $savedCount],
        ];
    }

    /**
     * Build a matched settlement↔payment detail row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private function pairDetail(array $row, ?array $payment, int $paymentId, bool $saved): array
    {
        $total = (float) ($payment['total'] ?? 0);

        return [
            'settlement' => [
                'id' => $row['id'],
                'reference' => $row['reference'],
                'transaction_date' => $row['transaction_date'],
                'transaction_time' => $row['transaction_time'],
                'amount' => $row['amount'],
                'status' => $row['status'] ?? null,
            ],
            'payment' => [
                'id' => $paymentId,
                'business_day' => $payment !== null ? GcorePaymentsService::businessDay((string) ($payment['created_at_pos'] ?? '')) : null,
                'total' => $total,
                'order_reference' => $payment['order_reference'] ?? null,
            ],
            'diff' => round(abs($total - (float) $row['amount']), 2),
            'saved' => $saved,
        ];
    }
}
