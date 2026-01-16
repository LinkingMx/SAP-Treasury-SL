<?php

use App\Http\Controllers\AfirmeController;
use App\Http\Controllers\BatchController;
use App\Models\BankAccount;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('tesoreria', function () {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('tesoreria/index', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account']),
        ]);
    })->name('tesoreria');

    Route::get('tesoreria/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::get('tesoreria/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
    Route::post('tesoreria/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::delete('tesoreria/batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
    Route::post('tesoreria/batches/{batch}/process-sap', [BatchController::class, 'processToSap'])->name('batches.process-sap');
    Route::post('tesoreria/batches/{batch}/transactions/{transaction}/reprocess', [BatchController::class, 'reprocessTransaction'])->name('batches.reprocess-transaction');
    Route::post('tesoreria/batches/error-log', [BatchController::class, 'downloadErrorLog'])->name('batches.error-log');
    Route::get('tesoreria/template/download', [BatchController::class, 'downloadTemplate'])->name('batches.template');

    // Afirme Integration
    Route::get('afirme', [AfirmeController::class, 'index'])->name('afirme');
    Route::get('afirme/payments', [AfirmeController::class, 'getPayments'])->name('afirme.payments');
    Route::post('afirme/download', [AfirmeController::class, 'downloadTxt'])->name('afirme.download');
});

require __DIR__.'/settings.php';
