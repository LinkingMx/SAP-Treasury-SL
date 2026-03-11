<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    public function __construct(
        private SapServiceLayer $sapService,
        private BankStatementService $bankStatementService
    ) {}

    /**
     * Run full reconciliation: parse file, query SAP, match.
     *
     * @param  callable|null  $onProgress  fn(array $event): void -- emits progress events for streaming
     * @return array{matched: array, unmatched_extracto: array, unmatched_sap: array, summary: array}
     */
    public function validate(
        UploadedFile $file,
        Branch $branch,
        BankAccount $bankAccount,
        string $dateFrom,
        string $dateTo,
        ?callable $onProgress = null
    ): array {
        $emit = $onProgress ?? fn (array $e) => null;

        // Step 1: Analyze file structure
        $emit(['event' => 'step', 'step' => 1, 'message' => 'Analizando estructura del archivo...']);
        $analysis = $this->bankStatementService->analyzeFile($file);
        $parseConfig = $analysis['parse_config'];

        // Step 2: Parse transactions from the file
        $emit(['event' => 'step', 'step' => 2, 'message' => 'Extrayendo transacciones del extracto bancario...']);
        $parseResult = $this->bankStatementService->parseOnly($file, $parseConfig);
        $extractoRows = $parseResult['transactions'];

        $emit(['event' => 'step', 'step' => 2, 'detail' => count($extractoRows).' movimientos encontrados']);

        // Step 3: Query SAP for BankPages in the same period
        $emit(['event' => 'step', 'step' => 3, 'message' => 'Consultando BankPages en SAP...']);

        try {
            if ($this->sapService->isLoggedIn()) {
                $this->sapService->logout();
            }
            $this->sapService->login($branch->sap_database);

            $sapRows = $this->sapService->getBankPages(
                $bankAccount->account,
                $dateFrom,
                $dateTo
            );

            $this->sapService->logout();
        } catch (\Exception $e) {
            Log::error('Failed to query SAP BankPages for reconciliation', [
                'error' => $e->getMessage(),
                'branch_id' => $branch->id,
                'account' => $bankAccount->account,
            ]);

            throw new \RuntimeException('Error al consultar SAP: '.$e->getMessage());
        }

        $emit(['event' => 'step', 'step' => 3, 'detail' => count($sapRows).' movimientos en SAP']);

        // Step 4: Run matching algorithm
        $emit(['event' => 'step', 'step' => 4, 'message' => 'Ejecutando algoritmo de conciliacion...']);
        $result = $this->reconcile($extractoRows, $sapRows);

        $emit(['event' => 'step', 'step' => 5, 'message' => 'Conciliacion completada.']);

        return $result;
    }

    /**
     * Match algorithm: for each extracto row, find SAP row with same date + same amount.
     * FIFO for duplicates.
     *
     * @return array{matched: array, unmatched_extracto: array, unmatched_sap: array, summary: array}
     */
    private function reconcile(array $extractoRows, array $sapRows): array
    {
        $matched = [];
        $unmatchedExtracto = [];

        // Normalize dates and index SAP rows for FIFO matching
        $normalizedSap = [];
        foreach ($sapRows as $index => $row) {
            $normalizedSap[$index] = $row;
            $normalizedSap[$index]['_normalized_date'] = $this->normalizeDate($row['due_date'] ?? '');
            $normalizedSap[$index]['_used'] = false;
        }

        // Totals for extracto
        $sumDebitExtracto = 0.0;
        $sumCreditExtracto = 0.0;

        foreach ($extractoRows as $extRow) {
            $extDate = $this->normalizeDate($extRow['due_date'] ?? '');
            $extDebit = round((float) ($extRow['debit_amount'] ?? 0), 2);
            $extCredit = round((float) ($extRow['credit_amount'] ?? 0), 2);

            $sumDebitExtracto += $extDebit;
            $sumCreditExtracto += $extCredit;

            $foundMatch = false;

            // FIFO: find first unused SAP row with same date and same amount
            foreach ($normalizedSap as $sapIndex => &$sapRow) {
                if ($sapRow['_used']) {
                    continue;
                }

                $sapDate = $sapRow['_normalized_date'];
                $sapDebit = round($sapRow['debit_amount'], 2);
                $sapCredit = round($sapRow['credit_amount'], 2);

                if ($extDate === $sapDate && (($extDebit > 0 && $extDebit === $sapDebit) || ($extCredit > 0 && $extCredit === $sapCredit))) {
                    $sapRow['_used'] = true;
                    $matched[] = [
                        'extracto' => $extRow,
                        'sap' => [
                            'sequence' => $sapRow['sequence'],
                            'account_code' => $sapRow['account_code'],
                            'due_date' => $sapRow['due_date'],
                            'debit_amount' => $sapRow['debit_amount'],
                            'credit_amount' => $sapRow['credit_amount'],
                            'memo' => $sapRow['memo'],
                            'reference' => $sapRow['reference'],
                        ],
                    ];
                    $foundMatch = true;
                    break;
                }
            }
            unset($sapRow);

            if (! $foundMatch) {
                $unmatchedExtracto[] = $extRow;
            }
        }

        // Collect unmatched SAP rows
        $unmatchedSap = [];
        $sumDebitSap = 0.0;
        $sumCreditSap = 0.0;

        foreach ($normalizedSap as $sapRow) {
            $sumDebitSap += $sapRow['debit_amount'];
            $sumCreditSap += $sapRow['credit_amount'];

            if (! $sapRow['_used']) {
                $unmatchedSap[] = [
                    'sequence' => $sapRow['sequence'],
                    'account_code' => $sapRow['account_code'],
                    'due_date' => $sapRow['due_date'],
                    'debit_amount' => $sapRow['debit_amount'],
                    'credit_amount' => $sapRow['credit_amount'],
                    'memo' => $sapRow['memo'],
                    'reference' => $sapRow['reference'],
                ];
            }
        }

        $summary = [
            'total_extracto' => count($extractoRows),
            'total_sap' => count($sapRows),
            'total_matched' => count($matched),
            'total_unmatched_extracto' => count($unmatchedExtracto),
            'total_unmatched_sap' => count($unmatchedSap),
            'sum_debit_extracto' => round($sumDebitExtracto, 2),
            'sum_credit_extracto' => round($sumCreditExtracto, 2),
            'sum_debit_sap' => round($sumDebitSap, 2),
            'sum_credit_sap' => round($sumCreditSap, 2),
            'difference_debit' => round($sumDebitExtracto - $sumDebitSap, 2),
            'difference_credit' => round($sumCreditExtracto - $sumCreditSap, 2),
        ];

        Log::info('Reconciliation completed', $summary);

        return [
            'matched' => $matched,
            'unmatched_extracto' => $unmatchedExtracto,
            'unmatched_sap' => $unmatchedSap,
            'summary' => $summary,
        ];
    }

    /**
     * Normalize a date string to Y-m-d format.
     */
    private function normalizeDate(string $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return $date;
        }
    }
}
