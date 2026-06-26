<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParrotPaymentsDataRequest;
use App\Models\Branch;
use App\Services\GcorePaymentsService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ParrotPaymentsController extends Controller
{
    public function __construct(protected GcorePaymentsService $gcore) {}

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

        $result = $this->gcore->allParrotOrderPayments($branch->payment_branch, $from, $to);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 502);
        }

        [$byType, $totals] = $this->aggregateByPaymentType($result['data']);

        return response()->json([
            'success' => true,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'payment_branch' => $branch->payment_branch,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'totals' => $totals,
            'by_payment_type' => $byType,
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
