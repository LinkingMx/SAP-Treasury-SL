<?php

use App\Http\Controllers\AfirmeController;
use App\Http\Controllers\AiIngestController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReconciliationController;
use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');

    Route::get('treasury', function () {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('treasury/index', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account', 'sap_bank_key']),
            'banks' => Bank::orderBy('name')->get(['id', 'name']),
        ]);
    })->name('treasury');

    Route::get('treasury/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::get('treasury/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
    Route::post('treasury/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::delete('treasury/batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
    Route::post('treasury/batches/{batch}/process-sap', [BatchController::class, 'processToSap'])->name('batches.process-sap');
    Route::post('treasury/batches/{batch}/transactions/{transaction}/reprocess', [BatchController::class, 'reprocessTransaction'])->name('batches.reprocess-transaction');
    Route::post('treasury/batches/error-log', [BatchController::class, 'downloadErrorLog'])->name('batches.error-log');
    Route::get('treasury/template/download', [BatchController::class, 'downloadTemplate'])->name('batches.template');

    // AI Intelligent Ingest
    Route::prefix('treasury/ai')->name('ai.')->group(function () {
        Route::post('analyze-structure', [AiIngestController::class, 'analyzeStructure'])->name('analyze-structure');
        Route::post('classify-preview', [AiIngestController::class, 'classifyPreview'])->name('classify-preview');
        Route::post('save-batch', [AiIngestController::class, 'saveBatch'])->name('save-batch');
        Route::post('save-rule', [AiIngestController::class, 'saveRule'])->name('save-rule');
        Route::get('banks', [AiIngestController::class, 'getBanks'])->name('banks');
    });

    // Bank Statements to SAP
    Route::prefix('treasury/bank-statements')->name('bank-statements.')->group(function () {
        Route::post('analyze', [BankStatementController::class, 'analyze'])->name('analyze');
        Route::post('preview', [BankStatementController::class, 'preview'])->name('preview');
        Route::post('send', [BankStatementController::class, 'send'])->name('send');
        Route::get('history', [BankStatementController::class, 'history'])->name('history');
        Route::get('{bankStatement}', [BankStatementController::class, 'show'])->name('show');
        Route::post('{bankStatement}/reprocess', [BankStatementController::class, 'reprocess'])->name('reprocess');
        Route::delete('{bankStatement}', [BankStatementController::class, 'destroy'])->name('destroy');
    });

    // Afirme Integration
    Route::get('afirme', [AfirmeController::class, 'index'])->name('afirme');
    Route::get('afirme/payments', [AfirmeController::class, 'getPayments'])->name('afirme.payments');
    Route::post('afirme/download', [AfirmeController::class, 'downloadTxt'])->name('afirme.download');

    // Bank Reconciliation - Bank Statement Upload
    Route::get('reconciliation/upload', function () {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('reconciliation/upload', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account', 'sap_bank_key']),
        ]);
    })->name('reconciliation.upload');

    // Validacion en Conciliacion
    Route::get('reconciliation/validation', [ReconciliationController::class, 'index'])
        ->name('reconciliation.validation');
    Route::post('reconciliation/validation/validate', [ReconciliationController::class, 'runValidation'])
        ->name('reconciliation.validation.validate');
    Route::post('reconciliation/validation/export', [ReconciliationController::class, 'export'])
        ->name('reconciliation.validation.export');

    // Pagos a SAP
    Route::get('payments/sap', function () {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('payments/sap', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account']),
        ]);
    })->name('payments.sap');

    Route::prefix('payments/sap')->name('vendor-payments.')->group(function () {
        Route::get('batches', [App\Http\Controllers\VendorPaymentController::class, 'index'])->name('index');
        Route::get('batches/{batch}', [App\Http\Controllers\VendorPaymentController::class, 'show'])->name('show');
        Route::post('batches', [App\Http\Controllers\VendorPaymentController::class, 'store'])->name('store');
        Route::delete('batches/{batch}', [App\Http\Controllers\VendorPaymentController::class, 'destroy'])->name('destroy');
        Route::post('batches/{batch}/process', [App\Http\Controllers\VendorPaymentController::class, 'processToSap'])->name('process');
        Route::post('batches/{batch}/payments/{cardCode}/reprocess', [App\Http\Controllers\VendorPaymentController::class, 'reprocessPayment'])->name('reprocess');
        Route::get('template/download', [App\Http\Controllers\VendorPaymentController::class, 'downloadTemplate'])->name('template');
        Route::post('batches/error-log', [App\Http\Controllers\VendorPaymentController::class, 'downloadErrorLog'])->name('error-log');
    });

    // Reports
    Route::get('reports/transactions', [App\Http\Controllers\ReportController::class, 'transactions'])->name('reports.transactions');
    Route::get('reports/transactions/data', [App\Http\Controllers\ReportController::class, 'transactionsData'])->name('reports.transactions.data');
});

require __DIR__.'/settings.php';
