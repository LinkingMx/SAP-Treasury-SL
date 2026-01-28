<?php

namespace App\Services;

use App\Enums\BankStatementStatus;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Services\Ai\BankLayoutAnalyzer;
use App\Services\Ai\TransactionClassifier;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class BankStatementService
{
    public function __construct(
        protected BankLayoutAnalyzer $layoutAnalyzer,
        protected TransactionClassifier $classifier,
        protected SapServiceLayer $sapService
    ) {}

    /**
     * Analyze the structure of an uploaded Excel file.
     *
     * @return array{parse_config: array, bank_name_guess: string|null, fingerprint: string, is_cached: bool}
     */
    public function analyzeFile(UploadedFile $file): array
    {
        return $this->layoutAnalyzer->analyze($file);
    }

    /**
     * Parse and classify transactions from an uploaded file.
     *
     * @return array{transactions: array, totals: array{debit: float, credit: float, count: int}}
     */
    public function parseAndClassify(
        UploadedFile $file,
        array $parseConfig,
        Branch $branch
    ): array {
        // Parse transactions using the layout analyzer
        $transactions = $this->layoutAnalyzer->parseTransactions($file, $parseConfig);

        if (empty($transactions)) {
            Log::warning('No transactions parsed from file', [
                'branch_id' => $branch->id,
            ]);

            return [
                'transactions' => [],
                'totals' => ['debit' => 0.0, 'credit' => 0.0, 'count' => 0],
            ];
        }

        // Get chart of accounts for classification
        $chartOfAccounts = $this->classifier->getChartOfAccounts($branch);

        // Classify transactions
        $classifiedTransactions = $this->classifier->classify($transactions, $chartOfAccounts);

        // Calculate totals
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($classifiedTransactions as $tx) {
            $totalDebit += $tx['debit_amount'] ?? 0.0;
            $totalCredit += $tx['credit_amount'] ?? 0.0;
        }

        Log::info('Parsed and classified transactions for bank statement', [
            'branch_id' => $branch->id,
            'count' => count($classifiedTransactions),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ]);

        return [
            'transactions' => $classifiedTransactions,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'count' => count($classifiedTransactions),
            ],
        ];
    }

    /**
     * Transform classified transactions to SAP BankPages format.
     *
     * SAP BankPages format:
     * - AccountCode: string, the bank account's GL code (from header)
     * - DueDate: string, ISO format with timestamp (2026-01-27T00:00:00Z)
     * - DebitAmount: float
     * - CreditAmount: float
     * - DocNumberType: 'bpdt_DocNum'
     * - Memo: string, description/memo
     * - Reference: string, "Ingreso" for credits, "Egreso" for debits
     *
     * @param  array  $transactions  Classified transactions
     * @param  string  $glAccountCode  The bank account's GL code to use for all rows
     * @return array SAP BankPages format rows
     */
    public function transformToSapFormat(array $transactions, string $glAccountCode): array
    {
        $rows = [];

        foreach ($transactions as $tx) {
            // Format date with timestamp for SAP (use each transaction's date)
            $date = $tx['due_date'];
            if (! str_contains($date, 'T')) {
                $date .= 'T00:00:00Z';
            }

            $debitAmount = (float) ($tx['debit_amount'] ?? 0);
            $creditAmount = (float) ($tx['credit_amount'] ?? 0);

            // Determine Reference based on movement type
            // Debit > 0 = Egreso (expense/outflow), Credit > 0 = Ingreso (income/inflow)
            $reference = $debitAmount > 0 ? 'Egreso' : 'Ingreso';

            $rows[] = [
                'AccountCode' => $glAccountCode,
                'DueDate' => $date,
                'DebitAmount' => $debitAmount,
                'CreditAmount' => $creditAmount,
                'DocNumberType' => 'bpdt_DocNum',
                'Memo' => $tx['memo'] ?? $tx['raw_memo'] ?? '',
                'Reference' => $reference,
            ];
        }

        return $rows;
    }

    /**
     * Generate a unique statement number.
     * Format: YYYY-MM-XXX where XXX is sequential within the month.
     */
    public function generateStatementNumber(int $branchId, Carbon $date): string
    {
        $yearMonth = $date->format('Y-m');

        // Count existing statements for this branch in this month
        $count = BankStatement::where('branch_id', $branchId)
            ->where('statement_number', 'LIKE', "{$yearMonth}-%")
            ->count();

        $sequence = str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);

        return "{$yearMonth}-{$sequence}";
    }

    /**
     * Send bank pages to SAP and save the local record.
     */
    public function sendToSap(
        Branch $branch,
        BankAccount $bankAccount,
        string $statementDate,
        array $rows,
        string $filename,
        int $userId
    ): BankStatement {
        // Generate statement number
        $statementDateCarbon = Carbon::parse($statementDate);
        $statementNumber = $this->generateStatementNumber($branch->id, $statementDateCarbon);

        // Build SAP payload (BankPages format) with original index tracking
        // Use the bank account's GL code for all rows
        $sapRows = $this->transformToSapFormat($rows, $bankAccount->account);
        foreach ($sapRows as $index => &$row) {
            $row['_original_index'] = $index;
        }
        unset($row);

        $payload = [
            'StatementDate' => $statementDate,
            'StatementNumber' => $statementNumber,
            'BankPages' => $sapRows,
        ];

        // Create local record first (pending)
        $bankStatement = BankStatement::create([
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'user_id' => $userId,
            'statement_date' => $statementDateCarbon,
            'statement_number' => $statementNumber,
            'original_filename' => $filename,
            'rows_count' => count($rows),
            'status' => BankStatementStatus::Pending,
            'payload' => $payload,
        ]);

        // Login to SAP (always fresh login to ensure correct database)
        try {
            // Logout any existing session to ensure we connect to the correct database
            if ($this->sapService->isLoggedIn()) {
                $this->sapService->logout();
            }
            $this->sapService->login($branch->sap_database);

            Log::info('=== SAP BANKPAGES SEND START ===', [
                'statement_number' => $statementNumber,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'sap_database' => $branch->sap_database,
                'rows_count' => count($sapRows),
                'timestamp' => now()->toDateTimeString(),
            ]);

            // Send to SAP using BankPages endpoint
            $result = $this->sapService->createBankPages($sapRows);

            // Update payload with SAP sequences for each row
            $updatedRows = $sapRows;
            foreach ($result['results'] as $rowResult) {
                $idx = $rowResult['index'];
                if (isset($updatedRows[$idx])) {
                    $updatedRows[$idx]['sap_sequence'] = $rowResult['sap_sequence'];
                    $updatedRows[$idx]['sap_error'] = $rowResult['error'];
                }
            }

            // Remove internal tracking field from stored payload
            foreach ($updatedRows as &$row) {
                unset($row['_original_index']);
            }
            unset($row);

            $payload['BankPages'] = $updatedRows;

            if ($result['success']) {
                $bankStatement->update([
                    'status' => BankStatementStatus::Sent,
                    'sap_doc_entry' => $result['created_count'],
                    'payload' => $payload,
                ]);

                Log::info('Bank pages sent to SAP successfully', [
                    'statement_id' => $bankStatement->id,
                    'statement_number' => $statementNumber,
                    'created_count' => $result['created_count'],
                ]);
            } else {
                $errorMessage = implode('; ', $result['errors']);
                $bankStatement->update([
                    'status' => BankStatementStatus::Failed,
                    'sap_error' => $errorMessage,
                    'payload' => $payload,
                ]);

                Log::error('Bank pages failed to send to SAP', [
                    'statement_id' => $bankStatement->id,
                    'statement_number' => $statementNumber,
                    'created' => $result['created_count'],
                    'failed' => $result['failed_count'],
                    'errors' => $result['errors'],
                ]);
            }

            // Logout from SAP
            $this->sapService->logout();

        } catch (\Exception $e) {
            $bankStatement->update([
                'status' => BankStatementStatus::Failed,
                'sap_error' => $e->getMessage(),
            ]);

            Log::error('Exception sending bank statement to SAP', [
                'statement_id' => $bankStatement->id,
                'statement_number' => $statementNumber,
                'exception' => $e->getMessage(),
            ]);
        }

        return $bankStatement->fresh();
    }

    /**
     * Get bank statement history for a branch.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getHistory(int $branchId, int $limit = 20)
    {
        return BankStatement::forBranch($branchId)
            ->with(['bankAccount', 'user'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Reprocess a failed bank statement by resending only failed rows to SAP.
     */
    public function reprocessStatement(int $bankStatementId, $user): BankStatement
    {
        $bankStatement = BankStatement::with(['branch', 'bankAccount'])->findOrFail($bankStatementId);

        // Verify user has access to this branch
        if (! $user->branches()->where('branches.id', $bankStatement->branch_id)->exists()) {
            throw new \Exception('No tienes acceso a esta sucursal.');
        }

        // Only allow reprocessing failed statements
        if ($bankStatement->status !== BankStatementStatus::Failed) {
            throw new \Exception('Solo se pueden reprocesar extractos fallidos.');
        }

        // Verify the payload exists
        if (empty($bankStatement->payload)) {
            throw new \Exception('El extracto no tiene datos de payload guardados para reprocesar.');
        }

        // Login to SAP (always fresh login to ensure correct database)
        try {
            // Logout any existing session to ensure we connect to the correct database
            if ($this->sapService->isLoggedIn()) {
                $this->sapService->logout();
            }
            $this->sapService->login($bankStatement->branch->sap_database);

            Log::info('=== SAP BANKPAGES REPROCESS START ===', [
                'statement_id' => $bankStatement->id,
                'statement_number' => $bankStatement->statement_number,
                'branch_id' => $bankStatement->branch->id,
                'branch_name' => $bankStatement->branch->name,
                'sap_database' => $bankStatement->branch->sap_database,
                'timestamp' => now()->toDateTimeString(),
            ]);

            // Get stored rows - support both new (BankPages) and old (BankStatementRows) format
            $storedRows = $bankStatement->payload['BankPages']
                ?? $bankStatement->payload['BankStatementRows']
                ?? [];

            // Filter only rows without sap_sequence (failed rows)
            // Use the bank account's GL code for all rows
            $glAccountCode = $bankStatement->bankAccount->account;
            $failedRows = [];
            $failedIndices = [];
            foreach ($storedRows as $index => $row) {
                if (empty($row['sap_sequence'])) {
                    $normalizedRow = $this->normalizeRowForSap($row, $glAccountCode);
                    $normalizedRow['_original_index'] = $index;
                    $failedRows[] = $normalizedRow;
                    $failedIndices[] = $index;
                }
            }

            if (empty($failedRows)) {
                throw new \Exception('No hay filas pendientes de procesar.');
            }

            Log::info('Reprocessing failed rows only', [
                'statement_id' => $bankStatement->id,
                'total_rows' => count($storedRows),
                'failed_rows' => count($failedRows),
                'failed_indices' => $failedIndices,
            ]);

            // Send only failed rows to SAP
            $result = $this->sapService->createBankPages($failedRows);

            // Update stored rows with new results
            $updatedRows = $storedRows;
            foreach ($result['results'] as $rowResult) {
                $idx = $rowResult['index'];
                if (isset($updatedRows[$idx])) {
                    $updatedRows[$idx]['sap_sequence'] = $rowResult['sap_sequence'];
                    $updatedRows[$idx]['sap_error'] = $rowResult['error'];
                }
            }

            // Update payload
            $payload = $bankStatement->payload;
            $payload['BankPages'] = $updatedRows;

            // Count remaining failed rows
            $remainingFailed = count(array_filter($updatedRows, fn ($r) => empty($r['sap_sequence'])));

            if ($result['success']) {
                $bankStatement->update([
                    'status' => BankStatementStatus::Sent,
                    'sap_doc_entry' => count($storedRows) - $remainingFailed,
                    'sap_error' => null,
                    'payload' => $payload,
                ]);

                Log::info('Bank pages reprocessed successfully', [
                    'statement_id' => $bankStatement->id,
                    'statement_number' => $bankStatement->statement_number,
                    'created_count' => $result['created_count'],
                ]);
            } else {
                $errorMessage = implode('; ', $result['errors']);
                $bankStatement->update([
                    'sap_error' => $errorMessage,
                    'payload' => $payload,
                ]);

                Log::error('Bank pages reprocess failed', [
                    'statement_id' => $bankStatement->id,
                    'statement_number' => $bankStatement->statement_number,
                    'created' => $result['created_count'],
                    'failed' => $result['failed_count'],
                    'remaining_failed' => $remainingFailed,
                    'errors' => $result['errors'],
                ]);
            }

            // Logout from SAP
            $this->sapService->logout();

        } catch (\Exception $e) {
            $bankStatement->update([
                'sap_error' => $e->getMessage(),
            ]);

            Log::error('Exception reprocessing bank pages', [
                'statement_id' => $bankStatement->id,
                'statement_number' => $bankStatement->statement_number,
                'exception' => $e->getMessage(),
            ]);
        }

        return $bankStatement->fresh();
    }

    /**
     * Normalize a single row to SAP BankPages format.
     *
     * @param  array  $row  The stored row data
     * @param  string  $glAccountCode  The bank account's GL code to use
     */
    protected function normalizeRowForSap(array $row, string $glAccountCode): array
    {
        // Extract date - ensure it has timestamp (use each transaction's date)
        $dueDate = $row['DueDate'] ?? $row['Date'] ?? null;
        if ($dueDate && ! str_contains($dueDate, 'T')) {
            $dueDate .= 'T00:00:00Z';
        }

        // Extract description - handle multiple field names
        $description = $row['Memo'] ?? $row['PaymentReference'] ?? $row['Details'] ?? '';

        // Extract amounts as floats
        $debitAmount = 0.0;
        $creditAmount = 0.0;

        if (isset($row['DebitAmount'])) {
            $debitAmount = (float) $row['DebitAmount'];
        } elseif (isset($row['Debit'])) {
            $debitAmount = (float) $row['Debit'];
        }

        if (isset($row['CreditAmount'])) {
            $creditAmount = (float) $row['CreditAmount'];
        } elseif (isset($row['Credit'])) {
            $creditAmount = (float) $row['Credit'];
        }

        // Handle Amount format (signed single column)
        if (isset($row['Amount'])) {
            $amount = (float) $row['Amount'];
            $debitAmount = $amount < 0 ? abs($amount) : 0.0;
            $creditAmount = $amount > 0 ? $amount : 0.0;
        }

        // Determine Reference based on movement type
        // Debit > 0 = Egreso (expense/outflow), Credit > 0 = Ingreso (income/inflow)
        $reference = $row['Reference'] ?? ($debitAmount > 0 ? 'Egreso' : 'Ingreso');

        return [
            'AccountCode' => $glAccountCode,
            'DueDate' => $dueDate,
            'DebitAmount' => $debitAmount,
            'CreditAmount' => $creditAmount,
            'DocNumberType' => $row['DocNumberType'] ?? 'bpdt_DocNum',
            'Memo' => $description,
            'Reference' => $reference,
        ];
    }
}
