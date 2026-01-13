<?php

namespace App\Http\Controllers;

use App\Enums\BatchStatus;
use App\Exports\TransactionsTemplateExport;
use App\Imports\TransactionsImport;
use App\Jobs\ProcessBatchToSapJob;
use App\Models\Batch;
use App\Models\Transaction;
use App\Services\SapServiceLayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
        ]);

        $batches = Batch::query()
            ->where('branch_id', $request->input('branch_id'))
            ->where('bank_account_id', $request->input('bank_account_id'))
            ->orderBy('processed_at', 'desc')
            ->paginate(10);

        return response()->json($batches);
    }

    public function show(Batch $batch): JsonResponse
    {
        $batch->load(['branch', 'bankAccount', 'user', 'transactions']);

        return response()->json([
            'id' => $batch->id,
            'uuid' => $batch->uuid,
            'filename' => $batch->filename,
            'total_records' => $batch->total_records,
            'total_debit' => $batch->total_debit,
            'total_credit' => $batch->total_credit,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'error_message' => $batch->error_message,
            'processed_at' => $batch->processed_at?->format('Y-m-d H:i:s'),
            'branch' => $batch->branch,
            'bank_account' => $batch->bankAccount,
            'user' => $batch->user?->name,
            'transactions' => $batch->transactions,
        ]);
    }

    public function destroy(Batch $batch): JsonResponse
    {
        $batch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lote eliminado exitosamente',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'branch_id.required' => 'Debe seleccionar una sucursal',
            'branch_id.exists' => 'La sucursal seleccionada no existe',
            'bank_account_id.required' => 'Debe seleccionar una cuenta bancaria',
            'bank_account_id.exists' => 'La cuenta bancaria seleccionada no existe',
            'file.required' => 'Debe seleccionar un archivo Excel',
            'file.mimes' => 'El archivo debe ser un Excel (.xlsx o .xls)',
            'file.max' => 'El archivo no debe superar 10MB',
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        try {
            $import = new TransactionsImport(
                branchId: (int) $request->input('branch_id'),
                bankAccountId: (int) $request->input('bank_account_id'),
                userId: (int) auth()->id(),
                filename: $file->getClientOriginalName()
            );

            Excel::import($import, $file);

            if ($import->hasErrors()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo contiene errores y no fue procesado',
                    'errors' => $import->getErrors(),
                    'error_count' => count($import->getErrors()),
                ], 422);
            }

            $batch = $import->getBatch();

            if (! $batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo crear el lote. El archivo puede estar vacío.',
                    'errors' => [['row' => 0, 'error' => 'El archivo no contiene datos válidos']],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Archivo procesado exitosamente',
                'batch' => [
                    'uuid' => $batch->uuid,
                    'total_records' => $batch->total_records,
                    'total_debit' => $batch->total_debit,
                    'total_credit' => $batch->total_credit,
                    'status' => $batch->status->value,
                    'status_label' => $batch->status->label(),
                    'processed_at' => $batch->processed_at->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'error' => implode(', ', $failure->errors()),
                ];
            }

            return response()->json([
                'success' => false,
                'message' => 'Error de validación en el archivo Excel',
                'errors' => $errors,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error importing Excel file', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo',
                'errors' => [['row' => 0, 'error' => $e->getMessage()]],
            ], 500);
        }
    }

    public function downloadErrorLog(Request $request): StreamedResponse
    {
        $request->validate([
            'errors' => ['required', 'array'],
        ]);

        $errors = $request->input('errors');
        $lines = ['=== ERRORES DE IMPORTACIÓN ===', '', 'Fecha: '.now()->format('Y-m-d H:i:s'), ''];

        foreach ($errors as $error) {
            $lines[] = "Fila {$error['row']}: {$error['error']}";
        }

        $lines[] = '';
        $lines[] = 'Total de errores: '.count($errors);

        $content = implode("\n", $lines);

        return Response::streamDownload(function () use ($content) {
            echo $content;
        }, 'errores_importacion_'.now()->format('Y-m-d_His').'.txt', [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return Excel::download(
            new TransactionsTemplateExport,
            'plantilla_transacciones.xlsx'
        );
    }

    public function processToSap(Batch $batch): JsonResponse
    {
        // Check if batch is already being processed or completed
        if ($batch->status === BatchStatus::Processing) {
            return response()->json([
                'success' => false,
                'message' => 'El lote ya está siendo procesado',
            ], 422);
        }

        if ($batch->status === BatchStatus::Completed) {
            return response()->json([
                'success' => false,
                'message' => 'El lote ya fue procesado exitosamente',
            ], 422);
        }

        // Update status to processing and clear any previous error
        $batch->update([
            'status' => BatchStatus::Processing,
            'error_message' => null,
        ]);

        // Dispatch job to queue
        ProcessBatchToSapJob::dispatch($batch);

        return response()->json([
            'success' => true,
            'message' => 'El lote ha sido enviado a procesar en SAP',
            'status' => BatchStatus::Processing->value,
            'status_label' => BatchStatus::Processing->label(),
        ]);
    }

    public function reprocessTransaction(Batch $batch, Transaction $transaction, SapServiceLayer $sap): JsonResponse
    {
        // Validate batch status
        if (! in_array($batch->status, [BatchStatus::Failed, BatchStatus::Pending])) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden reprocesar transacciones de lotes con estado pendiente o fallido',
            ], 422);
        }

        // Validate transaction belongs to batch
        if ($transaction->batch_id !== $batch->id) {
            return response()->json([
                'success' => false,
                'message' => 'La transacción no pertenece a este lote',
            ], 422);
        }

        // Validate transaction is not already processed
        if ($transaction->sap_number !== null) {
            return response()->json([
                'success' => false,
                'message' => 'La transacción ya fue procesada en SAP',
            ], 422);
        }

        // Load batch relationships
        $batch->load(['branch', 'bankAccount']);
        $branch = $batch->branch;
        $bankAccount = $batch->bankAccount;

        if (! $branch || ! $bankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'El lote no tiene sucursal o cuenta bancaria asociada',
            ], 422);
        }

        // Update batch status to processing
        $batch->update([
            'status' => BatchStatus::Processing,
            'error_message' => null,
        ]);

        // Clear transaction error before retry
        $transaction->update(['error' => null]);

        // Login to SAP
        try {
            $loggedIn = $sap->login($branch->sap_database);
            if (! $loggedIn) {
                $batch->update([
                    'status' => BatchStatus::Failed,
                    'error_message' => 'No se pudo iniciar sesión en SAP Service Layer',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo iniciar sesión en SAP Service Layer',
                ], 500);
            }
        } catch (\Exception $e) {
            $batch->update([
                'status' => BatchStatus::Failed,
                'error_message' => 'Error de conexión a SAP: '.$e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error de conexión a SAP: '.$e->getMessage(),
            ], 500);
        }

        // Process the transaction
        $bplId = $branch->sap_branch_id === 0 ? null : $branch->sap_branch_id;

        $result = $sap->createJournalEntry(
            transaction: $transaction,
            bankAccountCode: $bankAccount->account,
            ceco: $branch->ceco,
            bplId: $bplId
        );

        // Logout from SAP
        $sap->logout();

        if ($result['success']) {
            $transaction->update([
                'sap_number' => $result['jdt_num'],
                'error' => null,
            ]);

            Log::info('Transaction reprocessed successfully', [
                'transaction_id' => $transaction->id,
                'jdt_num' => $result['jdt_num'],
            ]);
        } else {
            $transaction->update([
                'error' => $result['error'],
            ]);

            Log::warning('Transaction reprocessing failed', [
                'transaction_id' => $transaction->id,
                'error' => $result['error'],
            ]);
        }

        // Check if all transactions in the batch are processed
        $unprocessedCount = $batch->transactions()->whereNull('sap_number')->count();

        if ($unprocessedCount === 0) {
            $batch->update([
                'status' => BatchStatus::Completed,
                'error_message' => null,
            ]);
        } else {
            $batch->update([
                'status' => BatchStatus::Failed,
                'error_message' => $unprocessedCount === 1
                    ? '1 transacción sin procesar'
                    : "{$unprocessedCount} transacciones sin procesar",
            ]);
        }

        // Reload transaction for response
        $transaction->refresh();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Transacción procesada exitosamente'
                : 'Error al procesar la transacción: '.$result['error'],
            'transaction' => $transaction,
            'batch_status' => $batch->fresh()->status->value,
            'batch_status_label' => $batch->fresh()->status->label(),
        ]);
    }
}
