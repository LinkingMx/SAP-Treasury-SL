<?php

namespace App\Http\Controllers;

use App\Services\TreasuryMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TreasuryMetricsService $metrics): Response
    {
        $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = auth()->user();
        $accessibleBranches = $user->branches()->get(['branches.id', 'branches.name', 'branches.sap_database']);

        $selectedBranchId = $request->input('branch_id');
        $branches = $selectedBranchId
            ? $accessibleBranches->where('id', (int) $selectedBranchId)->values()
            : $accessibleBranches;
        $branchIds = $branches->pluck('id');

        $from = $request->input('date_from', Carbon::now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', Carbon::now()->endOfMonth()->toDateString());
        $asOf = Carbon::now()->toDateString();

        return Inertia::render('dashboard', [
            'branches' => $accessibleBranches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
            'filters' => [
                'branch_id' => $selectedBranchId ? (string) $selectedBranchId : 'all',
                'date_from' => $from,
                'date_to' => $to,
            ],
            // Reconciliation is local + fast — deferred so the shell paints instantly.
            'reconciliation' => Inertia::defer(
                fn () => $metrics->reconciliationHealth($branchIds, $from, $to),
                'reconciliation',
            ),
            // SAP-backed groups load in parallel; gracefully unavailable off-prod.
            'cash' => Inertia::defer(fn () => $metrics->cashPosition($branches, $asOf), 'cash'),
            'payables' => Inertia::defer(fn () => $metrics->payablesAging($branches, $asOf), 'sap'),
            'receivables' => Inertia::defer(fn () => $metrics->receivablesAging($branches, $asOf), 'sap'),
        ]);
    }
}
