<?php

namespace App\Jobs;

use App\Enums\CustomerPaymentBatchStatus;
use App\Models\CustomerPaymentBatch;
use App\Models\CustomerPaymentInvoice;
use App\Services\SapServiceLayer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessCustomerPaymentsToSapJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public CustomerPaymentBatch $batch
    ) {}

    public function handle(SapServiceLayer $sap): void
    {
        Log::info('Starting SAP processing for customer payment batch', ['batch_id' => $this->batch->id]);

        $this->batch->load(['branch', 'bankAccount', 'invoices']);

        $branch = $this->batch->branch;
        $bankAccount = $this->batch->bankAccount;

        if (! $branch || ! $bankAccount) {
            Log::error('Batch missing branch or bank account', ['batch_id' => $this->batch->id]);
            $this->batch->update([
                'status' => CustomerPaymentBatchStatus::Failed,
                'error_message' => 'El lote no tiene sucursal o cuenta bancaria asociada',
            ]);

            return;
        }

        try {
            $loggedIn = $sap->login($branch->sap_database);
            if (! $loggedIn) {
                Log::error('SAP Login failed for customer payment batch', ['batch_id' => $this->batch->id]);
                $this->batch->update([
                    'status' => CustomerPaymentBatchStatus::Failed,
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
                'status' => CustomerPaymentBatchStatus::Failed,
                'error_message' => 'Error de conexión a SAP: '.$e->getMessage(),
            ]);

            return;
        }

        $hasErrors = false;
        $bplId = $branch->sap_branch_id === 0 ? null : $branch->sap_branch_id;

        $groupedInvoices = $this->batch->invoices->groupBy('card_code');

        foreach ($groupedInvoices as $cardCode => $invoices) {
            $unpaidInvoices = $invoices->filter(fn ($invoice) => $invoice->sap_doc_num === null);

            if ($unpaidInvoices->isEmpty()) {
                continue;
            }

            $resolvedDocEntries = [];
            $resolutionFailed = false;
            foreach ($unpaidInvoices as $invoice) {
                $resolved = $sap->resolveCustomerDocEntry($invoice->card_code, $invoice->doc_entry, $invoice->invoice_type);

                if (! $resolved['success']) {
                    CustomerPaymentInvoice::where('id', $invoice->id)->update(['error' => $resolved['error']]);
                    $resolutionFailed = true;
                    $hasErrors = true;

                    Log::warning('Customer DocNum resolution failed', [
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

            $result = $sap->createIncomingPayment($unpaidInvoices->all(), $bplId, $resolvedDocEntries);

            if ($result['success']) {
                foreach ($unpaidInvoices as $invoice) {
                    CustomerPaymentInvoice::where('id', $invoice->id)->update([
                        'sap_doc_num' => $result['doc_num'],
                        'error' => null,
                    ]);
                }

                Log::info('Customer payment processed successfully', [
                    'batch_id' => $this->batch->id,
                    'card_code' => $cardCode,
                    'doc_num' => $result['doc_num'],
                ]);
            } else {
                foreach ($unpaidInvoices as $invoice) {
                    CustomerPaymentInvoice::where('id', $invoice->id)->update([
                        'error' => $result['error'],
                    ]);
                }

                $hasErrors = true;

                Log::warning('Customer payment processing failed', [
                    'batch_id' => $this->batch->id,
                    'card_code' => $cardCode,
                    'error' => $result['error'],
                ]);
            }
        }

        $sap->logout();

        $this->batch->update([
            'status' => $hasErrors ? CustomerPaymentBatchStatus::Failed : CustomerPaymentBatchStatus::Completed,
            'error_message' => $hasErrors ? 'Algunos pagos no pudieron ser procesados' : null,
        ]);

        Log::info('Customer payment batch SAP processing completed', [
            'batch_id' => $this->batch->id,
            'status' => $hasErrors ? 'failed' : 'completed',
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessCustomerPaymentsToSapJob failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->batch->update([
            'status' => CustomerPaymentBatchStatus::Failed,
            'error_message' => 'Error en el proceso: '.($exception?->getMessage() ?? 'Error desconocido'),
        ]);
    }
}
