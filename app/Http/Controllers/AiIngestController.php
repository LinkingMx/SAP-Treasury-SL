<?php

namespace App\Http\Controllers;

use App\Enums\BatchStatus;
use App\Models\Bank;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\LearningRule;
use App\Models\Transaction;
use App\Services\Ai\BankLayoutAnalyzer;
use App\Services\Ai\TransactionClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiIngestController extends Controller
{
    public function __construct(
        protected BankLayoutAnalyzer $layoutAnalyzer,
        protected TransactionClassifier $classifier
    ) {}

    /**
     * Analyze the structure of an uploaded bank file.
     */
    public function analyzeStructure(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        try {
            $result = $this->layoutAnalyzer->analyze($request->file('file'));

            return response()->json([
                'success' => true,
                'parse_config' => $result['parse_config'],
                'bank_name_guess' => $result['bank_name_guess'],
                'fingerprint' => $result['fingerprint'],
                'is_cached' => $result['is_cached'],
            ]);
        } catch (\Exception $e) {
            Log::error('Structure analysis failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Parse and classify transactions from a file.
     */
    public function classifyPreview(Request $request): JsonResponse
    {
        // Increase execution time for large files and SAP queries
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'parse_config' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
            'rules_only' => 'nullable|boolean',
        ]);

        // Decode parse_config from JSON string
        $parseConfig = json_decode($request->input('parse_config'), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parseConfig)) {
            return response()->json([
                'success' => false,
                'message' => 'Configuración de parseo inválida.',
            ], 422);
        }

        try {
            // Parse transactions from file
            $transactions = $this->layoutAnalyzer->parseTransactions(
                $request->file('file'),
                $parseConfig
            );

            if (empty($transactions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron transacciones válidas en el archivo.',
                ], 422);
            }

            // Get branch for SAP database connection
            $branch = Branch::findOrFail($request->input('branch_id'));

            // Fetch chart of accounts from SAP
            $chartOfAccounts = $this->classifier->fetchChartOfAccounts($branch->sap_database);
            $sapConnected = ! empty($chartOfAccounts);

            // Classify transactions (rules_only skips AI classification)
            $rulesOnly = $request->boolean('rules_only', false);
            $classifiedTransactions = $this->classifier->classify($transactions, $chartOfAccounts, $rulesOnly);

            // Calculate totals
            $totalDebit = 0;
            $totalCredit = 0;
            $unclassifiedCount = 0;

            foreach ($classifiedTransactions as $t) {
                $totalDebit += $t['debit_amount'] ?? 0;
                $totalCredit += $t['credit_amount'] ?? 0;
                if ($t['sap_account_code'] === null) {
                    $unclassifiedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'transactions' => $classifiedTransactions,
                'summary' => [
                    'total_records' => count($classifiedTransactions),
                    'total_debit' => number_format($totalDebit, 2, '.', ''),
                    'total_credit' => number_format($totalCredit, 2, '.', ''),
                    'unclassified_count' => $unclassifiedCount,
                ],
                'chart_of_accounts' => $chartOfAccounts,
                'sap_connected' => $sapConnected,
                'sap_database' => $branch->sap_database,
            ]);
        } catch (\Exception $e) {
            Log::error('Classification preview failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Save the batch with classified transactions.
     */
    public function saveBatch(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'bank_id' => 'nullable|exists:banks,id',
            'filename' => 'required|string|max:255',
            'transactions' => 'required|array|min:1',
            'transactions.*.sequence' => 'required|integer|min:1',
            'transactions.*.due_date' => 'required|date',
            'transactions.*.memo' => 'required|string',
            'transactions.*.debit_amount' => 'nullable|numeric|min:0',
            'transactions.*.credit_amount' => 'nullable|numeric|min:0',
            'transactions.*.sap_account_code' => 'required|string|max:50',
            'transactions.*.sap_account_name' => 'nullable|string|max:150',
            'transactions.*.ai_suggested_account' => 'nullable|string|max:50',
        ]);

        // Validate that all transactions have a classification
        $transactions = $request->input('transactions');
        foreach ($transactions as $index => $t) {
            if (empty($t['sap_account_code'])) {
                return response()->json([
                    'success' => false,
                    'message' => "La transacción #{$t['sequence']} no tiene cuenta SAP asignada.",
                ], 422);
            }
        }

        try {
            $batch = DB::transaction(function () use ($request, $transactions) {
                // Calculate totals
                $totalDebit = 0;
                $totalCredit = 0;

                foreach ($transactions as $t) {
                    $totalDebit += $t['debit_amount'] ?? 0;
                    $totalCredit += $t['credit_amount'] ?? 0;
                }

                // Create batch
                $batch = Batch::create([
                    'branch_id' => $request->input('branch_id'),
                    'bank_account_id' => $request->input('bank_account_id'),
                    'bank_id' => $request->input('bank_id'),
                    'user_id' => $request->user()->id,
                    'filename' => $request->input('filename'),
                    'total_records' => count($transactions),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'status' => BatchStatus::Pending,
                ]);

                // Create transactions
                foreach ($transactions as $t) {
                    Transaction::create([
                        'batch_id' => $batch->id,
                        'sequence' => $t['sequence'],
                        'due_date' => $t['due_date'],
                        'memo' => $t['memo'],
                        'debit_amount' => $t['debit_amount'],
                        'credit_amount' => $t['credit_amount'],
                        'counterpart_account' => $t['sap_account_code'],
                    ]);
                }

                return $batch;
            });

            // Dispatch learning job if there were user corrections
            $hasCorrections = collect($transactions)->contains(function ($t) {
                return isset($t['ai_suggested_account'])
                    && $t['ai_suggested_account'] !== $t['sap_account_code'];
            });

            if ($hasCorrections) {
                // Dispatch job to learn from corrections
                dispatch(new \App\Jobs\LearnFromUserCorrections($batch, $transactions));
            }

            Log::info('AI Ingest batch saved', [
                'batch_id' => $batch->id,
                'uuid' => $batch->uuid,
                'total_records' => $batch->total_records,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lote guardado exitosamente.',
                'batch' => [
                    'uuid' => $batch->uuid,
                    'total_records' => $batch->total_records,
                    'total_debit' => $batch->total_debit,
                    'total_credit' => $batch->total_credit,
                    'status' => $batch->status->value,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save AI ingest batch', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el lote: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of banks for selection.
     */
    public function getBanks(): JsonResponse
    {
        $banks = Bank::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'banks' => $banks,
        ]);
    }

    /**
     * Save a learning rule from a transaction classification.
     */
    public function saveRule(Request $request): JsonResponse
    {
        $request->validate([
            'memo' => 'required|string',
            'sap_account_code' => 'required|string|max:50',
            'sap_account_name' => 'nullable|string|max:150',
            'pattern' => 'nullable|string',
        ]);

        $memo = $request->input('memo');
        $pattern = $request->input('pattern') ?: $this->extractPattern($memo);

        if (! $pattern || strlen($pattern) < 3) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo extraer un patrón válido del memo.',
            ], 422);
        }

        // Check if rule with same pattern already exists (update it)
        $existingRule = LearningRule::where('pattern', $pattern)->first();

        if ($existingRule) {
            // Update existing rule with new account
            $existingRule->update([
                'sap_account_code' => $request->input('sap_account_code'),
                'sap_account_name' => $request->input('sap_account_name'),
                'confidence_score' => 100,
                'source' => 'user_correction',
            ]);

            Log::info('Learning rule updated', [
                'pattern' => $pattern,
                'old_account' => $existingRule->getOriginal('sap_account_code'),
                'new_account' => $request->input('sap_account_code'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Regla actualizada.',
                'rule' => $existingRule->fresh(),
                'is_new' => false,
            ]);
        }

        // Create new rule
        $rule = LearningRule::create([
            'pattern' => $pattern,
            'match_type' => 'contains',
            'sap_account_code' => $request->input('sap_account_code'),
            'sap_account_name' => $request->input('sap_account_name'),
            'confidence_score' => 100,
            'source' => 'user_correction',
        ]);

        Log::info('Learning rule created', [
            'pattern' => $pattern,
            'account' => $request->input('sap_account_code'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Regla guardada exitosamente.',
            'rule' => $rule,
            'is_new' => true,
        ]);
    }

    /**
     * Extract a meaningful pattern from a memo.
     * Returns the full memo text to give AI maximum context.
     */
    protected function extractPattern(string $memo): ?string
    {
        // Normalize whitespace and clean up
        $cleaned = trim(preg_replace('/\s+/', ' ', $memo));

        if (strlen($cleaned) < 3) {
            return null;
        }

        // Return full memo in uppercase for consistent matching
        return strtoupper($cleaned);
    }
}
