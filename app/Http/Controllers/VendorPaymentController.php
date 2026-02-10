<?php

namespace App\Http\Controllers;

use App\Enums\VendorPaymentBatchStatus;
use App\Exports\VendorPaymentsTemplateExport;
use App\Imports\VendorPaymentsImport;
use App\Jobs\ProcessVendorPaymentsToSapJob;
use App\Models\VendorPaymentBatch;
use App\Services\SapServiceLayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class VendorPaymentController extends Controller
{
    /**
     * Get paginated list of batches.
     */
    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
        ]);

        $batches = VendorPaymentBatch::where('branch_id', $request->branch_id)
            ->where('bank_account_id', $request->bank_account_id)
            ->with(['branch:id,name', 'bankAccount:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($batches);
    }

    /**
     * Get batch detail with invoices.
     */
    public function show(VendorPaymentBatch $batch)
    {
        $batch->load([
            'branch:id,name',
            'bankAccount:id,name',
            'invoices' => function ($query) {
                $query->orderBy('card_code')->orderBy('line_num');
            },
        ]);

        return response()->json($batch);
    }

    /**
     * Store a new batch from Excel upload.
     */
    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();

        $import = new VendorPaymentsImport(
            branchId: $request->branch_id,
            bankAccountId: $request->bank_account_id,
            userId: auth()->id(),
            filename: $filename
        );

        try {
            Excel::import($import, $file);

            if ($import->hasErrors()) {
                return response()->json([
                    'success' => false,
                    'errors' => $import->getErrors(),
                ], 422);
            }

            $batch = $import->getBatch();

            return response()->json([
                'success' => true,
                'batch' => [
                    'uuid' => $batch->uuid,
                    'total_invoices' => $batch->total_invoices,
                    'total_payments' => $batch->total_payments,
                    'total_amount' => $batch->total_amount,
                    'processed_at' => $batch->processed_at->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Vendor payment batch import failed', [
                'error' => $e->getMessage(),
                'file' => $filename,
            ]);

            return response()->json([
                'success' => false,
                'errors' => [
                    ['row' => 0, 'error' => 'Error al procesar el archivo: '.$e->getMessage()],
                ],
            ], 422);
        }
    }

    /**
     * Delete a batch and its invoices.
     */
    public function destroy(VendorPaymentBatch $batch)
    {
        $batch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lote eliminado exitosamente',
        ]);
    }

    /**
     * Process batch to SAP (dispatch job).
     */
    public function processToSap(VendorPaymentBatch $batch)
    {
        if ($batch->status === VendorPaymentBatchStatus::Processing) {
            return response()->json([
                'success' => false,
                'message' => 'El lote ya está siendo procesado',
            ], 422);
        }

        if ($batch->status === VendorPaymentBatchStatus::Completed) {
            return response()->json([
                'success' => false,
                'message' => 'El lote ya fue procesado',
            ], 422);
        }

        // Update status to processing
        $batch->update([
            'status' => VendorPaymentBatchStatus::Processing,
            'error_message' => null,
        ]);

        // Dispatch job
        ProcessVendorPaymentsToSapJob::dispatch($batch);

        return response()->json([
            'success' => true,
            'message' => 'Procesamiento iniciado',
        ]);
    }

    /**
     * Reprocess a specific payment (by CardCode).
     */
    public function reprocessPayment(Request $request, VendorPaymentBatch $batch, string $cardCode)
    {
        // Validate batch is in a reprocessable state
        if (! in_array($batch->status, [VendorPaymentBatchStatus::Pending, VendorPaymentBatchStatus::Failed])) {
            return response()->json([
                'success' => false,
                'message' => 'El lote no puede ser reprocesado en su estado actual',
            ], 422);
        }

        // Get unpaid invoices for this vendor
        $invoices = $batch->invoices()
            ->where('card_code', $cardCode)
            ->whereNull('sap_doc_num')
            ->get();

        if ($invoices->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hay facturas pendientes para este proveedor',
            ], 422);
        }

        $branch = $batch->branch;
        $sap = app(SapServiceLayer::class);

        try {
            // Login to SAP
            $loggedIn = $sap->login($branch->sap_database);
            if (! $loggedIn) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo iniciar sesión en SAP',
                ], 500);
            }

            // Process payment
            $result = $sap->createVendorPayment($invoices->all());

            // Logout
            $sap->logout();

            if ($result['success']) {
                // Update invoices
                foreach ($invoices as $invoice) {
                    $invoice->update([
                        'sap_doc_num' => $result['doc_num'],
                        'error' => null,
                    ]);
                }

                // Check if all invoices in batch are now processed
                $remainingUnpaid = $batch->invoices()->whereNull('sap_doc_num')->count();
                if ($remainingUnpaid === 0) {
                    $batch->update([
                        'status' => VendorPaymentBatchStatus::Completed,
                        'error_message' => null,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Pago procesado exitosamente',
                    'doc_num' => $result['doc_num'],
                ]);
            }

            // Update invoices with error
            foreach ($invoices as $invoice) {
                $invoice->update([
                    'error' => $result['error'],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el pago: '.$result['error'],
            ], 422);

        } catch (\Exception $e) {
            Log::error('Reprocess vendor payment failed', [
                'batch_id' => $batch->id,
                'card_code' => $cardCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download Excel template.
     */
    public function downloadTemplate()
    {
        return Excel::download(
            new VendorPaymentsTemplateExport,
            'plantilla_pagos_proveedores.xlsx'
        );
    }

    /**
     * Download error log.
     */
    public function downloadErrorLog(Request $request)
    {
        $request->validate([
            'errors' => 'required|array',
            'errors.*.row' => 'required|integer',
            'errors.*.error' => 'required|string',
        ]);

        $errors = $request->input('errors');

        $content = "=== LOG DE ERRORES - PAGOS A PROVEEDORES ===\n\n";
        $content .= "Fecha: ".now()->format('Y-m-d H:i:s')."\n\n";

        foreach ($errors as $error) {
            if ($error['row'] > 0) {
                $content .= "Fila {$error['row']}: {$error['error']}\n";
            } else {
                $content .= "{$error['error']}\n";
            }
        }

        $content .= "\nTotal de errores: ".count($errors)."\n";

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="errores_pagos_'.now()->format('Y-m-d_His').'.txt"');
    }
}
