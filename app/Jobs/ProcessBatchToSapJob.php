<?php

namespace App\Jobs;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Transaction;
use App\Services\SapServiceLayer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessBatchToSapJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Batch $batch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SapServiceLayer $sap): void
    {
        Log::info('Starting SAP processing for batch', ['batch_id' => $this->batch->id]);

        // Load relationships
        $this->batch->load(['branch', 'bankAccount', 'transactions']);

        $branch = $this->batch->branch;
        $bankAccount = $this->batch->bankAccount;

        if (! $branch || ! $bankAccount) {
            Log::error('Batch missing branch or bank account', ['batch_id' => $this->batch->id]);
            $this->batch->update([
                'status' => BatchStatus::Failed,
                'error_message' => 'El lote no tiene sucursal o cuenta bancaria asociada',
            ]);

            return;
        }

        // Mark duplicate transactions that were already processed in previous batches
        $duplicatesSkipped = $this->markDuplicateTransactions();

        // If all transactions were duplicates, complete without connecting to SAP
        $remainingCount = $this->batch->transactions->whereNull('sap_number')->count();
        if ($remainingCount === 0) {
            Log::info('All transactions are duplicates, skipping SAP processing', [
                'batch_id' => $this->batch->id,
                'duplicates_skipped' => $duplicatesSkipped,
            ]);

            $this->batch->update([
                'status' => BatchStatus::Completed,
                'error_message' => $duplicatesSkipped > 0
                    ? "Se omitieron {$duplicatesSkipped} transacciones duplicadas (ya procesadas en lotes anteriores)"
                    : null,
            ]);

            return;
        }

        // Login to SAP
        try {
            $loggedIn = $sap->login($branch->sap_database);
            if (! $loggedIn) {
                Log::error('SAP Login failed for batch', ['batch_id' => $this->batch->id]);
                $this->batch->update([
                    'status' => BatchStatus::Failed,
                    'error_message' => 'No se pudo iniciar sesión en SAP Service Layer',
                ]);

                return;
            }
        } catch (\Exception $e) {
            Log::error('SAP Login exception', [
                'batch_id' => $this->batch->id,
                'error' => $e->getMessage(),
            ]);
            $this->batch->update([
                'status' => BatchStatus::Failed,
                'error_message' => 'Error de conexión a SAP: '.$e->getMessage(),
            ]);

            return;
        }

        $hasErrors = false;
        $bplId = $branch->sap_branch_id === 0 ? null : $branch->sap_branch_id;

        // Special code for transactions that should not be sent to SAP
        $skipSapCode = '__SKIP_SAP__';

        // Process each transaction
        foreach ($this->batch->transactions as $transaction) {
            // Skip already processed transactions
            if ($transaction->sap_number !== null) {
                continue;
            }

            // Skip transactions marked as "No enviar a SAP"
            if ($transaction->counterpart_account === $skipSapCode) {
                $transaction->update([
                    'sap_number' => 0, // Mark as processed but skipped
                    'error' => null,
                ]);
                Log::info('Transaction skipped (marked as no-SAP)', [
                    'transaction_id' => $transaction->id,
                ]);

                continue;
            }

            $result = $sap->createJournalEntry(
                transaction: $transaction,
                bankAccountCode: $bankAccount->account,
                ceco: $branch->ceco,
                bplId: $bplId
            );

            if ($result['success']) {
                $transaction->update([
                    'sap_number' => $result['jdt_num'],
                    'error' => null,
                ]);
                Log::info('Transaction processed successfully', [
                    'transaction_id' => $transaction->id,
                    'jdt_num' => $result['jdt_num'],
                ]);
            } else {
                $transaction->update([
                    'error' => $result['error'],
                ]);
                $hasErrors = true;
                Log::warning('Transaction processing failed', [
                    'transaction_id' => $transaction->id,
                    'error' => $result['error'],
                ]);
            }
        }

        // Logout from SAP
        $sap->logout();

        // Update batch status
        $errorMessage = null;
        if ($hasErrors && $duplicatesSkipped > 0) {
            $errorMessage = "Algunas transacciones fallaron. Se omitieron {$duplicatesSkipped} duplicadas.";
        } elseif ($hasErrors) {
            $errorMessage = 'Algunas transacciones no pudieron ser procesadas';
        } elseif ($duplicatesSkipped > 0) {
            $errorMessage = "Se omitieron {$duplicatesSkipped} transacciones duplicadas (ya procesadas en lotes anteriores)";
        }

        $this->batch->update([
            'status' => $hasErrors ? BatchStatus::Failed : BatchStatus::Completed,
            'error_message' => $errorMessage,
        ]);

        Log::info('Batch SAP processing completed', [
            'batch_id' => $this->batch->id,
            'status' => $hasErrors ? 'failed' : 'completed',
            'duplicates_skipped' => $duplicatesSkipped,
        ]);
    }

    /**
     * Detect and mark transactions that already exist processed in previous batches
     * for the same bank account. Uses FIFO counting to handle legitimate duplicates
     * within the same batch (e.g. multiple identical bank fees on the same day).
     */
    private function markDuplicateTransactions(): int
    {
        $unprocessed = $this->batch->transactions->filter(fn ($t) => $t->sap_number === null);

        if ($unprocessed->isEmpty()) {
            return 0;
        }

        $makeKey = fn ($date, $memo, $debit, $credit) => ($date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : (string) $date)
            .'|'.$memo
            .'|'.($debit ?? '0')
            .'|'.($credit ?? '0');

        // Count already-processed transactions per signature for this bank account
        $existingCounts = Transaction::query()
            ->join('batches', 'batches.id', '=', 'transactions.batch_id')
            ->where('batches.bank_account_id', $this->batch->bank_account_id)
            ->where('transactions.batch_id', '!=', $this->batch->id)
            ->whereNotNull('transactions.sap_number')
            ->where('transactions.sap_number', '>', 0)
            ->selectRaw('transactions.due_date, transactions.memo, transactions.debit_amount, transactions.credit_amount, COUNT(*) as cnt')
            ->groupBy('transactions.due_date', 'transactions.memo', 'transactions.debit_amount', 'transactions.credit_amount')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $makeKey($row->due_date, $row->memo, $row->debit_amount, $row->credit_amount) => (int) $row->cnt,
            ])
            ->all();

        if (empty($existingCounts)) {
            return 0;
        }

        // Group unprocessed transactions by signature
        $grouped = $unprocessed->groupBy(
            fn ($t) => $makeKey($t->due_date, $t->memo, $t->debit_amount, $t->credit_amount)
        );

        $skipped = 0;

        foreach ($grouped as $key => $transactions) {
            $existingCount = $existingCounts[$key] ?? 0;

            if ($existingCount <= 0) {
                continue;
            }

            // Mark up to existingCount transactions as duplicates (FIFO)
            $toSkip = min($existingCount, $transactions->count());

            foreach ($transactions->take($toSkip) as $transaction) {
                $transaction->update([
                    'sap_number' => 0,
                    'error' => 'Omitido: transacción duplicada (ya procesada en un lote anterior)',
                ]);
                $skipped++;
            }

            Log::info('Duplicate transactions detected', [
                'batch_id' => $this->batch->id,
                'signature' => $key,
                'existing_count' => $existingCount,
                'new_count' => $transactions->count(),
                'skipped' => $toSkip,
            ]);
        }

        return $skipped;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        // Update batch status BEFORE logging to prevent stuck "processing" state
        // if the log file is not writable
        $this->batch->update([
            'status' => BatchStatus::Failed,
            'error_message' => 'Error en el proceso: '.($exception?->getMessage() ?? 'Error desconocido'),
        ]);

        Log::error('ProcessBatchToSapJob failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
