<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Batch;
use App\Models\Transaction;
use App\Models\VendorPaymentBatch;
use App\Models\VendorPaymentInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('dashboard', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account']),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $branchIds = $request->input('branch_id')
            ? [(int) $request->input('branch_id')]
            : auth()->user()->branches()->pluck('branches.id')->toArray();

        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : now()->startOfMonth();

        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : now()->endOfDay();

        return response()->json([
            'kpis' => $this->getKpis($branchIds, $dateFrom, $dateTo),
            'cashflow' => $this->getCashflow($branchIds, $dateFrom, $dateTo),
            'batch_status' => $this->getBatchStatusDistribution($branchIds, $dateFrom, $dateTo),
            'recent_activity' => $this->getRecentActivity($branchIds),
            'failed_batches' => $this->getFailedBatches($branchIds),
        ]);
    }

    /**
     * @param  array<int>  $branchIds
     */
    private function getKpis(array $branchIds, Carbon $dateFrom, Carbon $dateTo): array
    {
        $pendingBatches = Batch::whereIn('branch_id', $branchIds)
            ->whereIn('status', ['pending', 'failed'])
            ->count();

        $pendingVendorBatches = VendorPaymentBatch::whereIn('branch_id', $branchIds)
            ->whereIn('status', ['pending', 'failed'])
            ->count();

        $failedTransactions = Transaction::query()
            ->join('batches', 'batches.id', '=', 'transactions.batch_id')
            ->whereIn('batches.branch_id', $branchIds)
            ->whereNull('transactions.sap_number')
            ->whereNotNull('transactions.error')
            ->count();

        $failedInvoices = VendorPaymentInvoice::query()
            ->join('vendor_payment_batches', 'vendor_payment_batches.id', '=', 'vendor_payment_invoices.batch_id')
            ->whereIn('vendor_payment_batches.branch_id', $branchIds)
            ->whereNull('vendor_payment_invoices.sap_doc_num')
            ->whereNotNull('vendor_payment_invoices.error')
            ->count();

        $processedAmounts = Transaction::query()
            ->join('batches', 'batches.id', '=', 'transactions.batch_id')
            ->whereIn('batches.branch_id', $branchIds)
            ->whereNotNull('transactions.sap_number')
            ->where('transactions.sap_number', '>', 0)
            ->whereBetween('transactions.due_date', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(SUM(transactions.debit_amount), 0) as total_debit, COALESCE(SUM(transactions.credit_amount), 0) as total_credit')
            ->first();

        $vendorPaymentsAmount = VendorPaymentInvoice::query()
            ->join('vendor_payment_batches', 'vendor_payment_batches.id', '=', 'vendor_payment_invoices.batch_id')
            ->whereIn('vendor_payment_batches.branch_id', $branchIds)
            ->whereNotNull('vendor_payment_invoices.sap_doc_num')
            ->whereBetween('vendor_payment_invoices.created_at', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(SUM(vendor_payment_invoices.sum_applied), 0) as total')
            ->value('total');

        return [
            'pending_batches' => $pendingBatches + $pendingVendorBatches,
            'failed_items' => $failedTransactions + $failedInvoices,
            'processed_amount' => [
                'debit' => (float) $processedAmounts->total_debit,
                'credit' => (float) $processedAmounts->total_credit,
            ],
            'vendor_payments_amount' => (float) $vendorPaymentsAmount,
        ];
    }

    /**
     * @param  array<int>  $branchIds
     * @return array<int, array{date: string, debit: float, credit: float}>
     */
    private function getCashflow(array $branchIds, Carbon $dateFrom, Carbon $dateTo): array
    {
        return Transaction::query()
            ->join('batches', 'batches.id', '=', 'transactions.batch_id')
            ->whereIn('batches.branch_id', $branchIds)
            ->whereNotNull('transactions.sap_number')
            ->where('transactions.sap_number', '>', 0)
            ->whereBetween('transactions.due_date', [$dateFrom, $dateTo])
            ->selectRaw('transactions.due_date as date, COALESCE(SUM(transactions.debit_amount), 0) as debit, COALESCE(SUM(transactions.credit_amount), 0) as credit')
            ->groupBy('transactions.due_date')
            ->orderBy('transactions.due_date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->format('d M'),
                'debit' => (float) $row->debit,
                'credit' => (float) $row->credit,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $branchIds
     * @return array<string, int>
     */
    private function getBatchStatusDistribution(array $branchIds, Carbon $dateFrom, Carbon $dateTo): array
    {
        $batchCounts = Batch::whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $vendorCounts = VendorPaymentBatch::whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return [
            'completed' => ($batchCounts['completed'] ?? 0) + ($vendorCounts['completed'] ?? 0),
            'pending' => ($batchCounts['pending'] ?? 0) + ($vendorCounts['pending'] ?? 0),
            'processing' => ($batchCounts['processing'] ?? 0) + ($vendorCounts['processing'] ?? 0),
            'failed' => ($batchCounts['failed'] ?? 0) + ($vendorCounts['failed'] ?? 0),
        ];
    }

    /**
     * @param  array<int>  $branchIds
     * @return array<int, array<string, mixed>>
     */
    private function getRecentActivity(array $branchIds): array
    {
        $batches = Batch::query()
            ->whereIn('branch_id', $branchIds)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'filename', 'status', 'total_records', 'total_debit', 'total_credit', 'user_id', 'created_at'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'type' => 'batch',
                'filename' => $b->filename,
                'status' => $b->status->value,
                'total_records' => $b->total_records,
                'amount' => (float) $b->total_debit + (float) $b->total_credit,
                'user' => $b->user?->name,
                'created_at' => $b->created_at->toISOString(),
            ]);

        $vendorBatches = VendorPaymentBatch::query()
            ->whereIn('branch_id', $branchIds)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'filename', 'status', 'total_invoices', 'total_amount', 'user_id', 'created_at'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'type' => 'vendor_payment',
                'filename' => $b->filename,
                'status' => $b->status->value,
                'total_records' => $b->total_invoices,
                'amount' => (float) $b->total_amount,
                'user' => $b->user?->name,
                'created_at' => $b->created_at->toISOString(),
            ]);

        return $batches->merge($vendorBatches)
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $branchIds
     * @return array<int, array<string, mixed>>
     */
    private function getFailedBatches(array $branchIds): array
    {
        $batches = Batch::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'failed')
            ->with(['branch:id,name', 'bankAccount:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'uuid', 'branch_id', 'bank_account_id', 'filename', 'error_message', 'total_records', 'created_at'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'type' => 'batch',
                'filename' => $b->filename,
                'error' => $b->error_message,
                'total_records' => $b->total_records,
                'branch' => $b->branch?->name,
                'bank_account' => $b->bankAccount?->name,
                'created_at' => $b->created_at->toISOString(),
            ]);

        $vendorBatches = VendorPaymentBatch::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'failed')
            ->with(['branch:id,name', 'bankAccount:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'uuid', 'branch_id', 'bank_account_id', 'filename', 'error_message', 'total_invoices', 'created_at'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'type' => 'vendor_payment',
                'filename' => $b->filename,
                'error' => $b->error_message,
                'total_records' => $b->total_invoices,
                'branch' => $b->branch?->name,
                'bank_account' => $b->bankAccount?->name,
                'created_at' => $b->created_at->toISOString(),
            ]);

        return $batches->merge($vendorBatches)
            ->sortByDesc('created_at')
            ->take(5)
            ->values()
            ->all();
    }
}
