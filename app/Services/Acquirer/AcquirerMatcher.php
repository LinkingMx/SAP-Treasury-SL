<?php

namespace App\Services\Acquirer;

use App\Models\PaymentOrder;

/**
 * Pure, IO-free matching of acquirer settlement rows against the Parrot payments
 * returned by the gCore API. No database or HTTP — fully unit-testable.
 */
final class AcquirerMatcher
{
    private const EXACT_EPSILON = 0.0001;

    /**
     * Match each settlement row to at most one Parrot payment (1-to-1).
     *
     * @param  array<int, array{transaction_date: string, transaction_time?: string|null, amount: float|int}>  $settlementRows
     * @param  array<int, array{id?: int, payment_type_name?: string, total?: float|int, status?: string, created_at_pos?: string, order_reference?: string|null}>  $apiPayments
     * @param  array<int, int>  $excludePaymentIds  Parrot payment ids already matched elsewhere (e.g. prior uploads)
     * @param  int|null  $businessDayStartHour  when set, compare restaurant business days (e.g. 5 = 05:00 cutoff) instead of calendar dates
     * @return array<int, MatchResult> one result per settlement row, in input order
     */
    public function match(array $settlementRows, array $apiPayments, MatchRule $rule, array $excludePaymentIds = [], ?int $businessDayStartHour = null): array
    {
        $used = array_fill_keys($excludePaymentIds, true);
        $results = [];

        // Bucket payments by day so each settlement only scans its own day's
        // candidates: O(n·m) → O(n·k). Critical for card volumes (3k+ rows).
        $paymentsByDay = [];
        foreach ($apiPayments as $payment) {
            $day = $businessDayStartHour === null
                ? substr((string) ($payment['created_at_pos'] ?? ''), 0, 10)
                : $this->businessDay((string) ($payment['created_at_pos'] ?? ''), $businessDayStartHour);
            $paymentsByDay[$day][] = $payment;
        }

        foreach ($settlementRows as $index => $row) {
            $rowDay = $businessDayStartHour === null
                ? $row['transaction_date']
                : $this->businessDay($row['transaction_date'].' '.($row['transaction_time'] ?? '00:00:00'), $businessDayStartHour);

            $best = $this->findBest($row, $paymentsByDay[$rowDay] ?? [], $rule, $used, $businessDayStartHour);

            if ($best === null) {
                $results[] = new MatchResult($index, null, null, null, null, null);

                continue;
            }

            $payment = $best['payment'];
            $paymentId = (int) ($payment['id'] ?? 0);
            $used[$paymentId] = true;

            $results[] = new MatchResult(
                rowIndex: $index,
                parrotPaymentId: $paymentId,
                orderReference: $payment['order_reference'] ?? null,
                paymentTotal: (float) ($payment['total'] ?? 0),
                diff: $best['diff'],
                method: $best['diff'] <= self::EXACT_EPSILON
                    ? PaymentOrder::METHOD_AUTO_EXACT
                    : PaymentOrder::METHOD_AUTO_FUZZY,
            );
        }

        return $results;
    }

    /**
     * Find the closest unused candidate payment for a settlement row.
     *
     * @param  array{transaction_date: string, transaction_time?: string|null, amount: float|int}  $row
     * @param  array<int, array<string, mixed>>  $apiPayments
     * @param  array<int, bool>  $used
     * @return array{payment: array<string, mixed>, diff: float}|null
     */
    private function findBest(array $row, array $apiPayments, MatchRule $rule, array $used, ?int $businessDayStartHour = null): ?array
    {
        $amount = (float) $row['amount'];
        $best = null;

        $rowDay = $businessDayStartHour === null
            ? $row['transaction_date']
            : $this->businessDay($row['transaction_date'].' '.($row['transaction_time'] ?? '00:00:00'), $businessDayStartHour);

        foreach ($apiPayments as $payment) {
            if (($payment['status'] ?? null) !== 'CHARGED') {
                continue;
            }

            if (! in_array($payment['payment_type_name'] ?? '', $rule->parrotTypes, true)) {
                continue;
            }

            $paymentDay = $businessDayStartHour === null
                ? substr((string) ($payment['created_at_pos'] ?? ''), 0, 10)
                : $this->businessDay((string) ($payment['created_at_pos'] ?? ''), $businessDayStartHour);

            if ($paymentDay !== $rowDay) {
                continue;
            }

            $paymentId = (int) ($payment['id'] ?? 0);
            if (isset($used[$paymentId])) {
                continue;
            }

            $diff = abs((float) ($payment['total'] ?? 0) - $amount);
            if ($diff > $rule->tolerance + self::EXACT_EPSILON) {
                continue;
            }

            if (! $this->withinTimeWindow($row, $payment, $rule)) {
                continue;
            }

            if ($best === null || $diff < $best['diff']) {
                $best = ['payment' => $payment, 'diff' => $diff];
            }
        }

        return $best;
    }

    /**
     * Check the optional time window between settlement time and payment time.
     *
     * @param  array{transaction_date: string, transaction_time?: string|null}  $row
     * @param  array<string, mixed>  $payment
     */
    private function withinTimeWindow(array $row, array $payment, MatchRule $rule): bool
    {
        if ($rule->timeWindowSeconds === null || empty($row['transaction_time'])) {
            return true;
        }

        // Both the Parrot payment time and the settlement time are Mexico
        // local wall-clock. Strip the payment's timezone offset so we compare
        // the literal local instants, not tz-shifted ones.
        $paymentLocal = str_replace('T', ' ', substr((string) ($payment['created_at_pos'] ?? ''), 0, 19));
        $paymentTs = strtotime($paymentLocal);
        $rowTs = strtotime($row['transaction_date'].' '.$row['transaction_time']);

        if ($paymentTs === false || $rowTs === false) {
            return true;
        }

        return abs($paymentTs - $rowTs) <= $rule->timeWindowSeconds;
    }

    /**
     * Restaurant business day (Y-m-d) for a local datetime: before the cutoff
     * hour counts as the previous day. Timezone suffixes are ignored.
     */
    private function businessDay(string $dateTime, int $startHour): string
    {
        $local = substr(str_replace('T', ' ', trim($dateTime)), 0, 19);
        $ts = strtotime($local);

        if ($ts === false) {
            return substr($local, 0, 10);
        }

        if ((int) date('G', $ts) < $startHour) {
            $ts = strtotime('-1 day', $ts);
        }

        return date('Y-m-d', $ts);
    }
}
