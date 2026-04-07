<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VendorPaymentInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function transactions(): Response
    {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('reports/transactions', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account']),
            'users' => User::whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function transactionsData(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sap_number' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'in:all,batch,vendor_payment'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
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

        $type = $request->input('type', 'all');
        $perPage = (int) $request->input('per_page', 25);

        $batchRows = collect();
        $vendorRows = collect();

        if ($type === 'all' || $type === 'batch') {
            $batchRows = $this->getBatchTransactions($branchIds, $dateFrom, $dateTo, $request);
        }

        if ($type === 'all' || $type === 'vendor_payment') {
            $vendorRows = $this->getVendorPaymentTransactions($branchIds, $dateFrom, $dateTo, $request);
        }

        // Merge, sort by date desc, paginate manually
        $merged = $batchRows->merge($vendorRows)->sortByDesc('date')->values();

        $page = max(1, (int) $request->input('page', 1));
        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        // Summary
        $summary = [
            'total_records' => $total,
            'total_debit' => $merged->sum('debit'),
            'total_credit' => $merged->sum('credit'),
            'total_amount' => $merged->sum('amount'),
        ];

        return response()->json([
            'data' => $items,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'per_page' => $perPage,
            'total' => $total,
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array<int>  $branchIds
     */
    private function getBatchTransactions(array $branchIds, Carbon $dateFrom, Carbon $dateTo, Request $request): \Illuminate\Support\Collection
    {
        $query = Transaction::query()
            ->join('batches', 'batches.id', '=', 'transactions.batch_id')
            ->join('branches', 'branches.id', '=', 'batches.branch_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'batches.bank_account_id')
            ->leftJoin('users', 'users.id', '=', 'batches.user_id')
            ->whereIn('batches.branch_id', $branchIds)
            ->whereBetween('transactions.due_date', [$dateFrom, $dateTo])
            ->whereNotNull('transactions.sap_number')
            ->where('transactions.sap_number', '>', 0);

        if ($request->filled('bank_account_id')) {
            $query->where('batches.bank_account_id', $request->input('bank_account_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('batches.user_id', $request->input('user_id'));
        }

        if ($request->filled('sap_number')) {
            $query->where('transactions.sap_number', $request->input('sap_number'));
        }

        return $query->select([
            'transactions.id',
            'transactions.due_date as date',
            'transactions.memo as description',
            'transactions.debit_amount as debit',
            'transactions.credit_amount as credit',
            'transactions.sap_number',
            'transactions.counterpart_account',
            'batches.id as batch_id',
            'batches.uuid as batch_uuid',
            'batches.filename as batch_filename',
            'branches.name as branch_name',
            'bank_accounts.name as bank_account_name',
            'bank_accounts.account as bank_account_code',
            'users.name as user_name',
        ])
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'type' => 'batch',
                'type_label' => 'Extracto Bancario',
                'date' => $row->date,
                'description' => $row->description,
                'debit' => (float) ($row->debit ?? 0),
                'credit' => (float) ($row->credit ?? 0),
                'amount' => (float) ($row->debit ?? 0) + (float) ($row->credit ?? 0),
                'sap_number' => $row->sap_number,
                'counterpart_account' => $row->counterpart_account,
                'card_code' => null,
                'card_name' => null,
                'batch_id' => $row->batch_id,
                'batch_uuid' => $row->batch_uuid,
                'batch_filename' => $row->batch_filename,
                'branch' => $row->branch_name,
                'bank_account' => $row->bank_account_name,
                'bank_account_code' => $row->bank_account_code,
                'user' => $row->user_name,
            ]);
    }

    /**
     * @param  array<int>  $branchIds
     */
    private function getVendorPaymentTransactions(array $branchIds, Carbon $dateFrom, Carbon $dateTo, Request $request): \Illuminate\Support\Collection
    {
        $query = VendorPaymentInvoice::query()
            ->join('vendor_payment_batches', 'vendor_payment_batches.id', '=', 'vendor_payment_invoices.batch_id')
            ->join('branches', 'branches.id', '=', 'vendor_payment_batches.branch_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'vendor_payment_batches.bank_account_id')
            ->leftJoin('users', 'users.id', '=', 'vendor_payment_batches.user_id')
            ->whereIn('vendor_payment_batches.branch_id', $branchIds)
            ->whereBetween('vendor_payment_invoices.doc_date', [$dateFrom, $dateTo])
            ->whereNotNull('vendor_payment_invoices.sap_doc_num');

        if ($request->filled('bank_account_id')) {
            $query->where('vendor_payment_batches.bank_account_id', $request->input('bank_account_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('vendor_payment_batches.user_id', $request->input('user_id'));
        }

        if ($request->filled('sap_number')) {
            $query->where('vendor_payment_invoices.sap_doc_num', $request->input('sap_number'));
        }

        return $query->select([
            'vendor_payment_invoices.id',
            'vendor_payment_invoices.doc_date as date',
            'vendor_payment_invoices.card_code',
            'vendor_payment_invoices.card_name',
            'vendor_payment_invoices.sum_applied',
            'vendor_payment_invoices.sap_doc_num',
            'vendor_payment_invoices.invoice_type',
            'vendor_payment_invoices.doc_entry',
            'vendor_payment_batches.id as batch_id',
            'vendor_payment_batches.uuid as batch_uuid',
            'vendor_payment_batches.filename as batch_filename',
            'branches.name as branch_name',
            'bank_accounts.name as bank_account_name',
            'bank_accounts.account as bank_account_code',
            'users.name as user_name',
        ])
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'type' => 'vendor_payment',
                'type_label' => 'Pago a Proveedor',
                'date' => $row->date,
                'description' => "{$row->card_name} (Doc #{$row->doc_entry})",
                'debit' => 0.0,
                'credit' => 0.0,
                'amount' => (float) $row->sum_applied,
                'sap_number' => $row->sap_doc_num,
                'counterpart_account' => null,
                'card_code' => $row->card_code,
                'card_name' => $row->card_name,
                'batch_id' => $row->batch_id,
                'batch_uuid' => $row->batch_uuid,
                'batch_filename' => $row->batch_filename,
                'branch' => $row->branch_name,
                'bank_account' => $row->bank_account_name,
                'bank_account_code' => $row->bank_account_code,
                'user' => $row->user_name,
            ]);
    }
}
