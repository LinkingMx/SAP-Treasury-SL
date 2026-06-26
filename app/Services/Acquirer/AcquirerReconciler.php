<?php

namespace App\Services\Acquirer;

use App\Models\Acquirer;
use App\Models\ExternalSettlement;
use App\Services\GcorePaymentsService;
use Carbon\Carbon;

/**
 * On-the-fly reconciliation of the loaded external_settlements against a set of
 * gCore Parrot payments, by restaurant business day. Does NOT persist anything —
 * it just reports which payments are matched, per payment type and per acquirer.
 */
final class AcquirerReconciler
{
    public function __construct(private AcquirerMatcher $matcher) {}

    /**
     * @param  array<int, array<string, mixed>>  $gcorePayments  gCore payments for the branch + window
     * @return array{
     *   matched_payment_ids: array<int, int>,
     *   covered_types: array<int, string>,
     *   by_type: array<string, array{matched_count: int, matched_sum: float}>,
     *   by_acquirer: array<string, array{name: string, settlements: int, matched: int, orphans: int, pending: int}>
     * }
     */
    public function reconcile(int $branchId, string $from, string $to, array $gcorePayments): array
    {
        $startHour = (int) substr((string) config('services.gcore.business_day_start', '05:00:00'), 0, 2);

        $paymentTypeById = [];
        foreach ($gcorePayments as $payment) {
            $paymentTypeById[(int) ($payment['id'] ?? 0)] = $payment['payment_type_name'] ?? 'Sin tipo';
        }

        $rowsByAcquirer = $this->loadSettlementRows($branchId, $from, $to, $startHour);

        $matchedPaymentIds = [];
        $coveredTypes = [];
        $byType = [];
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

            $results = $this->matcher->match($rows, $candidates, $rule, array_keys($matchedPaymentIds), $startHour);

            $matched = 0;
            foreach ($results as $result) {
                if (! $result->matched()) {
                    continue;
                }

                $matched++;
                $matchedPaymentIds[$result->parrotPaymentId] = $result->parrotPaymentId;

                $type = $paymentTypeById[$result->parrotPaymentId] ?? 'Sin tipo';
                $byType[$type] ??= ['matched_count' => 0, 'matched_sum' => 0.0];
                $byType[$type]['matched_count']++;
                $byType[$type]['matched_sum'] += (float) $result->paymentTotal;
            }

            $byAcquirer[$acquirer->code] = [
                'name' => $acquirer->name,
                'settlements' => count($rows),
                'matched' => $matched,
                'orphans' => count($rows) - $matched,
                'pending' => count($chargedCandidates) - $matched,
            ];
        }

        return [
            'matched_payment_ids' => array_values($matchedPaymentIds),
            'covered_types' => array_values(array_unique($coveredTypes)),
            'by_type' => $byType,
            'by_acquirer' => $byAcquirer,
        ];
    }

    /**
     * Load non-cancelled settlement rows for the branch whose business day falls
     * within [from, to], grouped by acquirer_id.
     *
     * @return array<int, array<int, array{transaction_date: string, transaction_time: string|null, amount: float}>>
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
            ->get(['acquirer_id', 'transaction_date', 'transaction_time', 'amount']);

        $grouped = [];

        foreach ($settlements as $settlement) {
            $businessDay = GcorePaymentsService::businessDay(
                $settlement->transaction_date->format('Y-m-d').' '.($settlement->transaction_time ?? '00:00:00'),
            );

            if ($businessDay < $from || $businessDay > $to) {
                continue;
            }

            $grouped[$settlement->acquirer_id][] = [
                'transaction_date' => $settlement->transaction_date->format('Y-m-d'),
                'transaction_time' => $settlement->transaction_time,
                'amount' => (float) $settlement->amount,
            ];
        }

        return $grouped;
    }
}
