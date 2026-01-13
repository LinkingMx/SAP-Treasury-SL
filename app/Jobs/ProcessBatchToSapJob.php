<?php

namespace App\Jobs;

use App\Enums\BatchStatus;
use App\Models\Batch;
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

        // Process each transaction
        foreach ($this->batch->transactions as $transaction) {
            // Skip already processed transactions
            if ($transaction->sap_number !== null) {
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
        $this->batch->update([
            'status' => $hasErrors ? BatchStatus::Failed : BatchStatus::Completed,
            'error_message' => $hasErrors ? 'Algunas transacciones no pudieron ser procesadas' : null,
        ]);

        Log::info('Batch SAP processing completed', [
            'batch_id' => $this->batch->id,
            'status' => $hasErrors ? 'failed' : 'completed',
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessBatchToSapJob failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->batch->update([
            'status' => BatchStatus::Failed,
            'error_message' => 'Error en el proceso: '.($exception?->getMessage() ?? 'Error desconocido'),
        ]);
    }
}
