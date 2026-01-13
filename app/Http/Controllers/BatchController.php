<?php

namespace App\Http\Controllers;

use App\Imports\TransactionsImport;
use App\Models\Batch;
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
}
