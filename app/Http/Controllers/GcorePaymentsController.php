<?php

namespace App\Http\Controllers;

use App\Http\Requests\GcorePaymentsRequest;
use App\Models\Branch;
use App\Services\GcorePaymentsService;
use Illuminate\Http\JsonResponse;

class GcorePaymentsController extends Controller
{
    public function __construct(protected GcorePaymentsService $gcore) {}

    /**
     * Return the Parrot POS order payments for a branch from the gCore API.
     *
     * The branch is matched against gCore using its `payment_branch` value.
     */
    public function parrotOrderPayments(GcorePaymentsRequest $request, Branch $branch): JsonResponse
    {
        if (empty($branch->payment_branch)) {
            return response()->json([
                'success' => false,
                'error' => "La sucursal «{$branch->name}» no tiene configurado el campo «Sucursal en API de Pagos» (payment_branch).",
            ], 422);
        }

        $from = $request->date('from')->format('Y-m-d');
        $to = $request->date('to')->format('Y-m-d');

        $filters = $request->only(['payment_type', 'status', 'page']);

        $result = $request->boolean('all')
            ? $this->gcore->allParrotOrderPayments($branch->payment_branch, $from, $to, $filters)
            : $this->gcore->parrotOrderPayments($branch->payment_branch, $from, $to, $filters);

        return response()->json($result, $result['success'] ? 200 : 502);
    }
}
