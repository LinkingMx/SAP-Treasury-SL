<?php

namespace App\Http\Controllers;

use App\Enums\CustomerPaymentBatchStatus;
use App\Exports\CustomerPaymentsTemplateExport;
use App\Imports\CustomerPaymentsImport;
use App\Jobs\ProcessCustomerPaymentsToSapJob;
use App\Models\CustomerPaymentBatch;
use App\Models\CustomerPaymentInvoice;
use App\Services\SapServiceLayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class CustomerPaymentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
        ]);

        $batches = CustomerPaymentBatch::where('branch_id', $request->branch_id)
            ->where('bank_account_id', $request->bank_account_id)
            ->with(['branch:id,name', 'bankAccount:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($batches);
    }

    public function show(CustomerPaymentBatch $batch)
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

    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'process_date' => 'required|date',
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ], [
            'process_date.required' => 'La fecha de proceso es obligatoria.',
            'process_date.date' => 'La fecha de proceso no es valida.',
        ]);

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();

        $import = new CustomerPaymentsImport(
            branchId: $request->branch_id,
            bankAccountId: $request->bank_account_id,
            userId: auth()->id(),
            filename: $filename,
            processDate: $request->input('process_date')
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
            Log::error('Customer payment batch import failed', [
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

    public function destroy(CustomerPaymentBatch $batch)
    {
        $batch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lote eliminado exitosamente',
        ]);
    }

    public function processToSap(CustomerPaymentBatch $batch)
    {
        if ($batch->status === CustomerPaymentBatchStatus::Processing) {
            return response()->json([
                'success' => false,
                'message' => 'El lote ya está siendo procesado',
            ], 422);
        }

        if ($batch->status === CustomerPaymentBatchStatus::Completed) {
            return response()->json([
                'success' => false,
                'message' => 'El lote ya fue procesado',
            ], 422);
        }

        $batch->update([
            'status' => CustomerPaymentBatchStatus::Processing,
            'error_message' => null,
        ]);

        ProcessCustomerPaymentsToSapJob::dispatch($batch);

        return response()->json([
            'success' => true,
            'message' => 'Procesamiento iniciado',
        ]);
    }

    public function reprocessPayment(Request $request, CustomerPaymentBatch $batch, string $cardCode)
    {
        if (! in_array($batch->status, [CustomerPaymentBatchStatus::Pending, CustomerPaymentBatchStatus::Failed])) {
            return response()->json([
                'success' => false,
                'message' => 'El lote no puede ser reprocesado en su estado actual',
            ], 422);
        }

        $invoices = $batch->invoices()
            ->where('card_code', $cardCode)
            ->whereNull('sap_doc_num')
            ->get();

        if ($invoices->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hay facturas pendientes para este cliente',
            ], 422);
        }

        $branch = $batch->branch;
        $sap = app(SapServiceLayer::class);

        try {
            $loggedIn = $sap->login($branch->sap_database);
            if (! $loggedIn) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo iniciar sesión en SAP',
                ], 500);
            }

            $resolvedDocEntries = [];
            foreach ($invoices as $invoice) {
                $resolved = $sap->resolveCustomerDocEntry($invoice->card_code, $invoice->doc_entry, $invoice->invoice_type);

                if (! $resolved['success']) {
                    CustomerPaymentInvoice::where('id', $invoice->id)->update(['error' => $resolved['error']]);

                    $sap->logout();

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al resolver factura: '.$resolved['error'],
                    ], 422);
                }

                $resolvedDocEntries[$invoice->id] = $resolved['doc_entry'];
            }

            $bplId = $branch->sap_branch_id === 0 ? null : $branch->sap_branch_id;
            $result = $sap->createIncomingPayment($invoices->all(), $bplId, $resolvedDocEntries);

            $sap->logout();

            if ($result['success']) {
                foreach ($invoices as $invoice) {
                    $invoice->update([
                        'sap_doc_num' => $result['doc_num'],
                        'error' => null,
                    ]);
                }

                $remainingUnpaid = $batch->invoices()->whereNull('sap_doc_num')->count();
                if ($remainingUnpaid === 0) {
                    $batch->update([
                        'status' => CustomerPaymentBatchStatus::Completed,
                        'error_message' => null,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Pago procesado exitosamente',
                    'doc_num' => $result['doc_num'],
                ]);
            }

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
            Log::error('Reprocess customer payment failed', [
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

    public function downloadTemplate()
    {
        return Excel::download(
            new CustomerPaymentsTemplateExport,
            'plantilla_pagos_clientes.xlsx'
        );
    }

    public function downloadErrorLog(Request $request)
    {
        $request->validate([
            'errors' => 'required|array',
            'errors.*.row' => 'required|integer',
            'errors.*.error' => 'required|string',
        ]);

        $errors = $request->input('errors');

        $content = "=== LOG DE ERRORES - PAGOS DE CLIENTES ===\n\n";
        $content .= 'Fecha: '.now()->format('Y-m-d H:i:s')."\n\n";

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
            ->header('Content-Disposition', 'attachment; filename="errores_cobros_'.now()->format('Y-m-d_His').'.txt"');
    }
}
