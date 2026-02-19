<?php

namespace App\Jobs;

use App\Enums\VendorPaymentBatchStatus;
use App\Models\VendorPaymentBatch;
use App\Models\VendorPaymentInvoice;
use App\Services\SapServiceLayer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVendorPaymentsToSapJob implements ShouldQueue
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
        public VendorPaymentBatch $batch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SapServiceLayer $sap): void
    {
        Log::info('Starting SAP processing for vendor payment batch', ['batch_id' => $this->batch->id]);

        // Load relationships
        $this->batch->load(['branch', 'bankAccount', 'invoices']);

        $branch = $this->batch->branch;
        $bankAccount = $this->batch->bankAccount;

        if (! $branch || ! $bankAccount) {
            Log::error('Batch missing branch or bank account', ['batch_id' => $this->batch->id]);
            $this->batch->update([
                'status' => VendorPaymentBatchStatus::Failed,
                'error_message' => 'El lote no tiene sucursal o cuenta bancaria asociada',
            ]);

            return;
        }

        // Login to SAP
        try {
            $loggedIn = $sap->login($branch->sap_database);
            if (! $loggedIn) {
                Log::error('SAP Login failed for vendor payment batch', ['batch_id' => $this->batch->id]);
                $this->batch->update([
                    'status' => VendorPaymentBatchStatus::Failed,
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
                'status' => VendorPaymentBatchStatus::Failed,
                'error_message' => 'Error de conexión a SAP: '.$e->getMessage(),
            ]);

            return;
        }

        $hasErrors = false;
        $bplId = $branch->sap_branch_id === 0 ? null : $branch->sap_branch_id;

        // Group invoices by CardCode
        $groupedInvoices = $this->batch->invoices->groupBy('card_code');

        // Process each vendor payment
        foreach ($groupedInvoices as $cardCode => $invoices) {
            // Skip already processed payments
            $unpaidInvoices = $invoices->filter(fn ($invoice) => $invoice->sap_doc_num === null);

            if ($unpaidInvoices->isEmpty()) {
                continue;
            }

            // Resolve DocNum → DocEntry for each invoice
            $resolvedDocEntries = [];
            $resolutionFailed = false;
            foreach ($unpaidInvoices as $invoice) {
                $resolved = $sap->resolveDocEntry($invoice->card_code, $invoice->doc_entry, $invoice->invoice_type);

                if (! $resolved['success']) {
                    VendorPaymentInvoice::where('id', $invoice->id)->update(['error' => $resolved['error']]);
                    $resolutionFailed = true;
                    $hasErrors = true;

                    Log::warning('DocNum resolution failed', [
                        'batch_id' => $this->batch->id,
                        'card_code' => $invoice->card_code,
                        'doc_num' => $invoice->doc_entry,
                        'error' => $resolved['error'],
                    ]);
                } else {
                    $resolvedDocEntries[$invoice->id] = $resolved['doc_entry'];
                }
            }

            if ($resolutionFailed) {
                continue;
            }

            $result = $sap->createVendorPayment($unpaidInvoices->all(), $bplId, $resolvedDocEntries);

            if ($result['success']) {
                // Update all invoices in this payment with the SAP doc number
                foreach ($unpaidInvoices as $invoice) {
                    VendorPaymentInvoice::where('id', $invoice->id)->update([
                        'sap_doc_num' => $result['doc_num'],
                        'error' => null,
                    ]);
                }

                Log::info('Vendor payment processed successfully', [
                    'batch_id' => $this->batch->id,
                    'card_code' => $cardCode,
                    'doc_num' => $result['doc_num'],
                ]);
            } else {
                // Update all invoices in this payment with the error
                foreach ($unpaidInvoices as $invoice) {
                    VendorPaymentInvoice::where('id', $invoice->id)->update([
                        'error' => $result['error'],
                    ]);
                }

                $hasErrors = true;

                Log::warning('Vendor payment processing failed', [
                    'batch_id' => $this->batch->id,
                    'card_code' => $cardCode,
                    'error' => $result['error'],
                ]);
            }
        }

        // Logout from SAP
        $sap->logout();

        // Update batch status
        $this->batch->update([
            'status' => $hasErrors ? VendorPaymentBatchStatus::Failed : VendorPaymentBatchStatus::Completed,
            'error_message' => $hasErrors ? 'Algunos pagos no pudieron ser procesados' : null,
        ]);

        Log::info('Vendor payment batch SAP processing completed', [
            'batch_id' => $this->batch->id,
            'status' => $hasErrors ? 'failed' : 'completed',
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessVendorPaymentsToSapJob failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->batch->update([
            'status' => VendorPaymentBatchStatus::Failed,
            'error_message' => 'Error en el proceso: '.($exception?->getMessage() ?? 'Error desconocido'),
        ]);
    }
}
