<?php

namespace App\Http\Controllers;

use App\Services\TreasuryMetricsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TreasuryMetricsService $metrics): Response
    {
        $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        $user = auth()->user();
        $accessibleBranches = $user->branches()->get(['branches.id', 'branches.name', 'branches.sap_database']);

        $selectedBranchId = $request->input('branch_id');
        $branches = $selectedBranchId
            ? $accessibleBranches->where('id', (int) $selectedBranchId)->values()
            : $accessibleBranches;

        return Inertia::render('dashboard', [
            'branches' => $accessibleBranches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
            'filters' => [
                'branch_id' => $selectedBranchId ? (string) $selectedBranchId : 'all',
            ],
            // SAP-backed treasury KPIs — deferred + grouped so each family
            // streams independently after the shell paints.
            'cash' => Inertia::defer(fn () => $metrics->cashPosition($branches), 'cash'),
            'payables' => Inertia::defer(fn () => $metrics->payablesAging($branches), 'sap'),
            'receivables' => Inertia::defer(fn () => $metrics->receivablesAging($branches), 'sap'),
        ]);
    }
}
