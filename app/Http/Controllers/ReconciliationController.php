<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService
    ) {}

    /**
     * Show the reconciliation validation page.
     */
    public function index(Request $request): Response
    {
        $branchIds = $request->user()->branches()->pluck('branches.id');

        return Inertia::render('reconciliation/validation', [
            'branches' => $request->user()->branches()->get(['branches.id', 'branches.name']),
            'bankAccounts' => BankAccount::whereIn('branch_id', $branchIds)
                ->get(['id', 'branch_id', 'name', 'account', 'sap_bank_key']),
        ]);
    }

    /**
     * Validate bank statement against SAP BankPages (streaming NDJSON).
     */
    public function runValidation(Request $request): StreamedResponse|JsonResponse
    {
        set_time_limit(600);
        ini_set('max_execution_time', '600');

        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'file' => 'required|file|extensions:xlsx,xls,csv|max:10240',
        ], [
            'branch_id.required' => 'La sucursal es obligatoria.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
            'bank_account_id.required' => 'La cuenta bancaria es obligatoria.',
            'bank_account_id.exists' => 'La cuenta bancaria seleccionada no existe.',
            'date_from.required' => 'La fecha inicial es obligatoria.',
            'date_from.date' => 'La fecha inicial no es valida.',
            'date_to.required' => 'La fecha final es obligatoria.',
            'date_to.date' => 'La fecha final no es valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'file.required' => 'El archivo es obligatorio.',
            'file.extensions' => 'El archivo debe ser de tipo xlsx, xls o csv.',
            'file.max' => 'El archivo no puede superar los 10 MB.',
        ]);

        $branch = Branch::findOrFail($request->input('branch_id'));

        // Verify user has access to this branch
        if (! $request->user()->branches()->where('branches.id', $branch->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sucursal.',
            ], 403);
        }

        $bankAccount = BankAccount::findOrFail($request->input('bank_account_id'));

        // Verify bank account belongs to branch
        if ($bankAccount->branch_id !== $branch->id) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta bancaria no pertenece a esta sucursal.',
            ], 422);
        }

        // Verify bank account has sap_bank_key
        if (! $bankAccount->sap_bank_key) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta bancaria no tiene configurada la Clave Bancaria SAP.',
            ], 422);
        }

        $file = $request->file('file');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        return response()->stream(function () use ($file, $branch, $bankAccount, $dateFrom, $dateTo) {
            $sendEvent = function (array $data) {
                echo json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            try {
                $result = $this->reconciliationService->validate(
                    $file,
                    $branch,
                    $bankAccount,
                    $dateFrom,
                    $dateTo,
                    $sendEvent
                );

                $sendEvent([
                    'event' => 'complete',
                    'data' => [
                        'matched' => $result['matched'],
                        'unmatched_extracto' => $result['unmatched_extracto'],
                        'unmatched_sap' => $result['unmatched_sap'],
                        'summary' => $result['summary'],
                        'balances' => $result['balances'] ?? null,
                        'branch_name' => $branch->name,
                        'bank_account_name' => $bankAccount->name.' ('.$bankAccount->account.')',
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'generated_at' => now()->toIso8601String(),
                        'generated_by' => auth()->user()->name,
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('Reconciliation validation failed', ['error' => $e->getMessage()]);
                $sendEvent([
                    'event' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Export reconciliation results to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'matched' => 'nullable|array',
            'unmatched_extracto' => 'nullable|array',
            'unmatched_sap' => 'nullable|array',
            'summary' => 'nullable|array',
        ]);

        $matched = $request->input('matched', []);
        $unmatchedExtracto = $request->input('unmatched_extracto', []);
        $unmatchedSap = $request->input('unmatched_sap', []);
        $summary = $request->input('summary', []);

        $filename = 'conciliacion_'.now()->format('Y-m-d_His').'.csv';

        return response()->stream(function () use ($matched, $unmatchedExtracto, $unmatchedSap, $summary) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Summary section
            fputcsv($handle, ['=== RESUMEN DE CONCILIACION ===']);
            fputcsv($handle, ['Total Extracto', $summary['total_extracto'] ?? 0]);
            fputcsv($handle, ['Total SAP', $summary['total_sap'] ?? 0]);
            fputcsv($handle, ['Total Conciliados', $summary['total_matched'] ?? 0]);
            fputcsv($handle, ['No Conciliados Extracto', $summary['total_unmatched_extracto'] ?? 0]);
            fputcsv($handle, ['No Conciliados SAP', $summary['total_unmatched_sap'] ?? 0]);
            fputcsv($handle, ['Suma Debito Extracto', $summary['sum_debit_extracto'] ?? 0]);
            fputcsv($handle, ['Suma Credito Extracto', $summary['sum_credit_extracto'] ?? 0]);
            fputcsv($handle, ['Suma Debito SAP', $summary['sum_debit_sap'] ?? 0]);
            fputcsv($handle, ['Suma Credito SAP', $summary['sum_credit_sap'] ?? 0]);
            fputcsv($handle, ['Diferencia Debitos', $summary['difference_debit'] ?? 0]);
            fputcsv($handle, ['Diferencia Creditos', $summary['difference_credit'] ?? 0]);
            fputcsv($handle, []);

            // Matched section
            fputcsv($handle, ['=== MOVIMIENTOS CONCILIADOS ===']);
            fputcsv($handle, [
                'Fecha Extracto', 'Debito Extracto', 'Credito Extracto', 'Memo Extracto',
                'Fecha SAP', 'Debito SAP', 'Credito SAP', 'Memo SAP', 'Secuencia SAP',
            ]);
            foreach ($matched as $row) {
                $ext = $row['extracto'] ?? [];
                $sap = $row['sap'] ?? [];
                fputcsv($handle, [
                    $ext['due_date'] ?? '',
                    $ext['debit_amount'] ?? 0,
                    $ext['credit_amount'] ?? 0,
                    $ext['memo'] ?? $ext['raw_memo'] ?? '',
                    $sap['due_date'] ?? '',
                    $sap['debit_amount'] ?? 0,
                    $sap['credit_amount'] ?? 0,
                    $sap['memo'] ?? '',
                    $sap['sequence'] ?? '',
                ]);
            }
            fputcsv($handle, []);

            // Unmatched extracto section
            fputcsv($handle, ['=== MOVIMIENTOS NO CONCILIADOS - EXTRACTO ===']);
            fputcsv($handle, ['Fecha', 'Debito', 'Credito', 'Memo']);
            foreach ($unmatchedExtracto as $row) {
                fputcsv($handle, [
                    $row['due_date'] ?? '',
                    $row['debit_amount'] ?? 0,
                    $row['credit_amount'] ?? 0,
                    $row['memo'] ?? $row['raw_memo'] ?? '',
                ]);
            }
            fputcsv($handle, []);

            // Unmatched SAP section
            fputcsv($handle, ['=== MOVIMIENTOS NO CONCILIADOS - SAP ===']);
            fputcsv($handle, ['Fecha', 'Debito', 'Credito', 'Memo', 'Secuencia', 'Referencia']);
            foreach ($unmatchedSap as $row) {
                fputcsv($handle, [
                    $row['due_date'] ?? '',
                    $row['debit_amount'] ?? 0,
                    $row['credit_amount'] ?? 0,
                    $row['memo'] ?? '',
                    $row['sequence'] ?? '',
                    $row['reference'] ?? '',
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
