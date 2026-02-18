<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Services\BankStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankStatementController extends Controller
{
    public function __construct(
        protected BankStatementService $bankStatementService
    ) {}

    /**
     * Analyze the structure of an uploaded bank file.
     */
    public function analyze(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|extensions:xlsx,xls,csv|max:10240',
        ]);

        try {
            $result = $this->bankStatementService->analyzeFile($request->file('file'));

            return response()->json([
                'success' => true,
                'parse_config' => $result['parse_config'],
                'bank_name_guess' => $result['bank_name_guess'],
                'fingerprint' => $result['fingerprint'],
                'is_cached' => $result['is_cached'],
            ]);
        } catch (\Exception $e) {
            Log::error('Bank statement structure analysis failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Parse and classify transactions from a file for preview (streamed with progress).
     */
    public function preview(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        set_time_limit(600);
        ini_set('max_execution_time', '600');

        $request->validate([
            'file' => 'required|file|extensions:xlsx,xls,csv|max:10240',
            'parse_config' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $parseConfig = json_decode($request->input('parse_config'), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parseConfig)) {
            return response()->stream(function () {
                echo json_encode(['event' => 'error', 'message' => 'Configuracion de parseo invalida.'])."\n";
            }, 422, ['Content-Type' => 'application/x-ndjson']);
        }

        $branch = Branch::findOrFail($request->input('branch_id'));
        if (! $request->user()->branches()->where('branches.id', $branch->id)->exists()) {
            return response()->stream(function () {
                echo json_encode(['event' => 'error', 'message' => 'No tienes acceso a esta sucursal.'])."\n";
            }, 403, ['Content-Type' => 'application/x-ndjson']);
        }

        $file = $request->file('file');

        return response()->stream(function () use ($file, $parseConfig) {
            $sendEvent = function (array $data) {
                echo json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            try {
                $result = $this->bankStatementService->parseOnly(
                    $file,
                    $parseConfig,
                    $sendEvent
                );

                $sendEvent([
                    'event' => 'complete',
                    'success' => true,
                    'transactions' => $result['transactions'],
                    'summary' => [
                        'total_records' => $result['totals']['count'],
                        'total_debit' => number_format($result['totals']['debit'], 2, '.', ''),
                        'total_credit' => number_format($result['totals']['credit'], 2, '.', ''),
                        'unclassified_count' => 0,
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('Bank statement preview failed', ['error' => $e->getMessage()]);
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
     * Send bank statement to SAP.
     */
    public function send(Request $request): JsonResponse
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'statement_date' => 'required|date_format:Y-m-d',
            'filename' => 'required|string|max:255',
            'transactions' => 'required|array|min:1',
            'transactions.*.due_date' => 'required|date',
            'transactions.*.memo' => 'required|string',
            'transactions.*.debit_amount' => 'nullable|numeric|min:0',
            'transactions.*.credit_amount' => 'nullable|numeric|min:0',
            'transactions.*.sap_account_code' => 'nullable|string|max:50',
        ]);

        // Verify user has access to this branch
        $branch = Branch::findOrFail($request->input('branch_id'));
        if (! $request->user()->branches()->where('branches.id', $branch->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sucursal.',
            ], 403);
        }

        // Verify bank account belongs to branch and has sap_bank_key
        $bankAccount = BankAccount::findOrFail($request->input('bank_account_id'));
        if ($bankAccount->branch_id !== $branch->id) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta bancaria no pertenece a esta sucursal.',
            ], 422);
        }

        if (! $bankAccount->sap_bank_key) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta bancaria no tiene configurada la Clave Bancaria SAP (sap_bank_key).',
            ], 422);
        }

        try {
            $bankStatement = $this->bankStatementService->sendToSap(
                $branch,
                $bankAccount,
                $request->input('statement_date'),
                $request->input('transactions'),
                $request->input('filename'),
                $request->user()->id
            );

            if ($bankStatement->status->value === 'sent') {
                return response()->json([
                    'success' => true,
                    'message' => 'Extracto bancario enviado a SAP exitosamente.',
                    'bank_statement' => [
                        'id' => $bankStatement->id,
                        'statement_number' => $bankStatement->statement_number,
                        'sap_doc_entry' => $bankStatement->sap_doc_entry,
                        'status' => $bankStatement->status->value,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al enviar extracto a SAP: '.$bankStatement->sap_error,
                    'bank_statement' => [
                        'id' => $bankStatement->id,
                        'statement_number' => $bankStatement->statement_number,
                        'status' => $bankStatement->status->value,
                        'error' => $bankStatement->sap_error,
                    ],
                ], 422);
            }
        } catch (\Exception $e) {
            Log::error('Bank statement send to SAP failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get bank statement detail with rows.
     */
    public function show(Request $request, int $bankStatementId): JsonResponse
    {
        $bankStatement = \App\Models\BankStatement::with(['branch', 'bankAccount', 'user'])
            ->findOrFail($bankStatementId);

        // Verify user has access to this branch
        if (! $request->user()->branches()->where('branches.id', $bankStatement->branch_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sucursal.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'bank_statement' => [
                'id' => $bankStatement->id,
                'statement_number' => $bankStatement->statement_number,
                'statement_date' => $bankStatement->statement_date->format('Y-m-d'),
                'original_filename' => $bankStatement->original_filename,
                'rows_count' => $bankStatement->rows_count,
                'status' => $bankStatement->status->value,
                'status_label' => $bankStatement->status->label(),
                'sap_doc_entry' => $bankStatement->sap_doc_entry,
                'sap_error' => $bankStatement->sap_error,
                'branch' => [
                    'id' => $bankStatement->branch->id,
                    'name' => $bankStatement->branch->name,
                ],
                'bank_account' => [
                    'id' => $bankStatement->bankAccount->id,
                    'name' => $bankStatement->bankAccount->name,
                    'sap_bank_key' => $bankStatement->bankAccount->sap_bank_key,
                ],
                'user' => [
                    'id' => $bankStatement->user->id,
                    'name' => $bankStatement->user->name,
                ],
                'rows' => $bankStatement->payload['BankPages'] ?? $bankStatement->payload['BankStatementRows'] ?? [],
                'created_at' => $bankStatement->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Reprocess a failed bank statement.
     */
    public function reprocess(Request $request, int $bankStatementId): JsonResponse
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        try {
            $bankStatement = $this->bankStatementService->reprocessStatement(
                $bankStatementId,
                $request->user()
            );

            if ($bankStatement->status->value === 'sent') {
                return response()->json([
                    'success' => true,
                    'message' => 'Extracto bancario reenviado a SAP exitosamente.',
                    'bank_statement' => [
                        'id' => $bankStatement->id,
                        'statement_number' => $bankStatement->statement_number,
                        'sap_doc_entry' => $bankStatement->sap_doc_entry,
                        'status' => $bankStatement->status->value,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al reenviar extracto a SAP: '.$bankStatement->sap_error,
                    'bank_statement' => [
                        'id' => $bankStatement->id,
                        'statement_number' => $bankStatement->statement_number,
                        'status' => $bankStatement->status->value,
                        'error' => $bankStatement->sap_error,
                    ],
                ], 422);
            }
        } catch (\Exception $e) {
            Log::error('Bank statement reprocess failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get bank statement history for a branch.
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        // Verify user has access to this branch
        $branch = Branch::findOrFail($request->input('branch_id'));
        if (! $request->user()->branches()->where('branches.id', $branch->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sucursal.',
            ], 403);
        }

        $limit = $request->input('limit', 20);
        $history = $this->bankStatementService->getHistory($branch->id, $limit);

        return response()->json([
            'success' => true,
            'history' => $history->map(fn ($item) => [
                'id' => $item->id,
                'statement_number' => $item->statement_number,
                'statement_date' => $item->statement_date->format('Y-m-d'),
                'original_filename' => $item->original_filename,
                'rows_count' => $item->rows_count,
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'sap_doc_entry' => $item->sap_doc_entry,
                'sap_error' => $item->sap_error,
                'bank_account' => [
                    'id' => $item->bankAccount->id,
                    'name' => $item->bankAccount->name,
                ],
                'user' => [
                    'id' => $item->user->id,
                    'name' => $item->user->name,
                ],
                'created_at' => $item->created_at->format('Y-m-d H:i:s'),
            ]),
        ]);
    }
}
