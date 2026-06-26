<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParrotPaymentsDataRequest;
use App\Models\Branch;
use App\Services\Acquirer\AcquirerReconciler;
use App\Services\GcorePaymentsService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ParrotPaymentsController extends Controller
{
    public function __construct(
        protected GcorePaymentsService $gcore,
        protected AcquirerReconciler $reconciler,
    ) {}

    /**
     * Render the Parrot payments page with the user's branches.
     */
    public function index(): Response
    {
        return Inertia::render('treasury/parrot-payments', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
        ]);
    }

    /**
     * Return Parrot payment totals grouped by payment type for a branch + period.
     *
     * Pulls every CHARGED payment from the gCore API and aggregates in PHP, so the
     * browser only receives the per-type totals, not the thousands of rows.
     */
    public function data(ParrotPaymentsDataRequest $request): JsonResponse
    {
        $branch = Branch::findOrFail($request->integer('branch_id'));

        if (empty($branch->payment_branch)) {
            return response()->json([
                'success' => false,
                'error' => "La sucursal «{$branch->name}» no tiene configurado el campo «Sucursal en API de Pagos» (payment_branch).",
            ], 422);
        }

        $from = $request->date('date_from')->format('Y-m-d');
        $to = $request->date('date_to')->format('Y-m-d');

        // Restaurants operate one day into the next: query the business-day window
        // (05:00 of date_from until 05:00 the morning after date_to).
        [$windowFrom, $windowTo] = GcorePaymentsService::businessDayWindow($from, $to);

        $result = $this->gcore->allParrotOrderPayments($branch->payment_branch, $windowFrom, $windowTo);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 502);
        }

        [$byType, $totals] = $this->aggregateByPaymentType($result['data']);

        // Reconcile the loaded acquirer settlements against these payments and
        // annotate each payment-type card with its conciliation status.
        $reconciliation = $this->reconciler->reconcile($branch->id, $from, $to, $result['data']);
        $covered = array_flip($reconciliation['covered_types']);

        $byType = array_map(function (array $entry) use ($reconciliation, $covered): array {
            $type = $entry['payment_type_name'];
            $matched = $reconciliation['by_type'][$type] ?? ['matched_count' => 0, 'matched_sum' => 0.0];

            $entry['has_settlements'] = isset($covered[$type]);
            $entry['matched_count'] = $matched['matched_count'];
            $entry['matched_sum'] = $matched['matched_sum'];
            $entry['reconciled_pct'] = $entry['count'] > 0
                ? round($matched['matched_count'] / $entry['count'] * 100, 1)
                : 0.0;

            return $entry;
        }, $byType);

        return response()->json([
            'success' => true,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'payment_branch' => $branch->payment_branch,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'window' => ['from' => $windowFrom, 'to' => $windowTo],
            'totals' => $totals,
            'by_payment_type' => $byType,
            'reconciliation' => $reconciliation['by_acquirer'],
        ]);
    }

    /**
     * Persist the conciliation: match the loaded settlements against the gCore
     * payments and write the new links into payment_orders (idempotent).
     */
    public function reconcile(ParrotPaymentsDataRequest $request): JsonResponse
    {
        $branch = Branch::findOrFail($request->integer('branch_id'));

        if (empty($branch->payment_branch)) {
            return response()->json([
                'success' => false,
                'error' => "La sucursal «{$branch->name}» no tiene configurado el campo «Sucursal en API de Pagos» (payment_branch).",
            ], 422);
        }

        $from = $request->date('date_from')->format('Y-m-d');
        $to = $request->date('date_to')->format('Y-m-d');

        [$windowFrom, $windowTo] = GcorePaymentsService::businessDayWindow($from, $to);
        $result = $this->gcore->allParrotOrderPayments($branch->payment_branch, $windowFrom, $windowTo);

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 502);
        }

        $persisted = $this->reconciler->persist($branch->id, $from, $to, $result['data'], $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => "Conciliación guardada: {$persisted['saved']} nuevas ({$persisted['already']} ya estaban).",
            'saved' => $persisted['saved'],
            'already' => $persisted['already'],
            'total_matched' => $persisted['total_matched'],
        ]);
    }

    /**
     * Row-level reconciliation detail for a payment type (drill-down).
     */
    public function detail(ParrotPaymentsDataRequest $request): JsonResponse
    {
        $paymentType = (string) $request->input('payment_type');
        if ($paymentType === '') {
            return response()->json(['success' => false, 'error' => 'Falta el tipo de pago.'], 422);
        }

        $branch = Branch::findOrFail($request->integer('branch_id'));

        if (empty($branch->payment_branch)) {
            return response()->json([
                'success' => false,
                'error' => "La sucursal «{$branch->name}» no tiene configurado el campo «Sucursal en API de Pagos» (payment_branch).",
            ], 422);
        }

        $from = $request->date('date_from')->format('Y-m-d');
        $to = $request->date('date_to')->format('Y-m-d');

        [$windowFrom, $windowTo] = GcorePaymentsService::businessDayWindow($from, $to);
        $result = $this->gcore->allParrotOrderPayments($branch->payment_branch, $windowFrom, $windowTo);

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 502);
        }

        return response()->json([
            'success' => true,
            'payment_type' => $paymentType,
            ...$this->reconciler->detail($branch->id, $from, $to, $result['data'], $paymentType),
        ]);
    }

    /**
     * Aggregate CHARGED payments by payment_type_name.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array{payment_type_name: string, count: int, sum_amount: float, sum_tip: float, sum_total: float}>, 1: array{count: int, sum_amount: float, sum_tip: float, sum_total: float}}
     */
    private function aggregateByPaymentType(array $rows): array
    {
        $groups = [];
        $totals = ['count' => 0, 'sum_amount' => 0.0, 'sum_tip' => 0.0, 'sum_total' => 0.0];

        foreach ($rows as $row) {
            if (($row['status'] ?? null) !== 'CHARGED') {
                continue;
            }

            $type = $row['payment_type_name'] ?? 'Sin tipo';
            $amount = (float) ($row['amount'] ?? 0);
            $tip = (float) ($row['tip'] ?? 0);
            $total = (float) ($row['total'] ?? 0);

            $groups[$type] ??= ['payment_type_name' => $type, 'count' => 0, 'sum_amount' => 0.0, 'sum_tip' => 0.0, 'sum_total' => 0.0];
            $groups[$type]['count']++;
            $groups[$type]['sum_amount'] += $amount;
            $groups[$type]['sum_tip'] += $tip;
            $groups[$type]['sum_total'] += $total;

            $totals['count']++;
            $totals['sum_amount'] += $amount;
            $totals['sum_tip'] += $tip;
            $totals['sum_total'] += $total;
        }

        $byType = array_values($groups);
        usort($byType, fn (array $a, array $b): int => $b['sum_total'] <=> $a['sum_total']);

        return [$byType, $totals];
    }
}
